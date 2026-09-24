<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateValidator;

// BASE_PATH comes from tests/Pest.php and resolves against the plugin
// root, so this constant stays stable if the test file moves.
const AGENT_TEMPLATES_DIR = BASE_PATH . '/agent-templates';

function loadAgentTemplate(string $basename): array
{
    $path = AGENT_TEMPLATES_DIR . '/' . $basename;
    expect(is_file($path))->toBeTrue("agent template {$basename} should exist on disk");

    $raw = file_get_contents($path);
    expect($raw)->not->toBeFalse("agent template {$basename} should be readable");

    $decoded = json_decode((string) $raw, true);
    expect($decoded)->toBeArray("agent template {$basename} should parse as JSON");

    return $decoded;
}

it('validates every shipped agent template against AgentTemplateValidator', function (): void {
    $files = glob(AGENT_TEMPLATES_DIR . '/*.json') ?: [];
    expect($files)->not->toBeEmpty('agent-templates/ directory should contain at least one .json');

    $validator = new AgentTemplateValidator();
    foreach ($files as $path) {
        $basename = basename((string) $path);
        $raw      = json_decode((string) file_get_contents((string) $path), true);
        expect($raw)->toBeArray("{$basename} should parse as JSON");

        $result = $validator->validate($raw);
        expect($result->isValid())->toBeTrue(
            "{$basename} should validate cleanly; errors: " . json_encode($result->errors()),
        );
    }
});

it('ships typst-expert as the sole plugin agent template', function (): void {
    $files = array_map(
        static fn(string $path): string => basename($path),
        glob(AGENT_TEMPLATES_DIR . '/*.json') ?: [],
    );
    expect($files)->toBe(['typst-expert.json']);

    $expert = loadAgentTemplate('typst-expert.json');
    expect($expert['id'])->toBe('typst-expert');
    expect($expert['version'])->toMatch('/^[0-9]+\.[0-9]+\.[0-9]+([+-].+)?$/');
    expect(trim((string) $expert['agent']['system_prompt']))->not->toBeEmpty();
});

it('wires SkillTool with the typst skill allowed on the typst-expert agent', function (): void {
    // SkillTool is how the agent's `skill(action: read, name: typst)`
    // directive is enforced server-side: SkillTool's allowed-list check
    // runs at tool time, regardless of the skill's allowedByDefault flag.
    // Precedent: spora-plugin-openai-image/agent-templates/image-agent.json.
    $expert = loadAgentTemplate('typst-expert.json');
    $tools  = $expert['tools'] ?? [];
    expect($tools)->toBeArray();

    $skillToolEntry = null;
    foreach ($tools as $entry) {
        if (($entry['tool_class'] ?? null) === 'Spora\\Tools\\SkillTool') {
            $skillToolEntry = $entry;
            break;
        }
    }
    expect($skillToolEntry)->toBeArray('typst-expert must include SkillTool in tools[]');
    expect($skillToolEntry['enabled'] ?? false)->toBeTrue();

    $allowed = $skillToolEntry['settings']['allowed_skills'] ?? null;
    expect($allowed)->toBeArray('SkillTool settings.allowed_skills must be an array');
    expect($allowed)->toContain('typst');
});

it('pins the per-operation approval semantics on typst_compile and typst_resources', function (): void {
    $expert = loadAgentTemplate('typst-expert.json');
    $tools  = $expert['tools'] ?? [];

    $opsByClass = [];
    foreach ($tools as $entry) {
        $class = (string) ($entry['tool_class'] ?? '');
        $ops   = [];
        foreach (($entry['operations'] ?? []) as $op) {
            $ops[(string) ($op['name'] ?? '')] = $op;
        }
        $opsByClass[$class] = $ops;
    }

    expect($opsByClass)->toHaveKey('Spora\\Plugins\\Typst\\Tools\\TypstCompileTool');
    expect($opsByClass)->toHaveKey('Spora\\Plugins\\Typst\\Tools\\TypstResourcesTool');

    expect($opsByClass['Spora\\Plugins\\Typst\\Tools\\TypstCompileTool']['render']['auto_approve'] ?? null)
        ->toBeTrue('render must be auto-approved since v1.1.0');
    expect($opsByClass['Spora\\Plugins\\Typst\\Tools\\TypstCompileTool']['inspect']['auto_approve'] ?? null)
        ->toBeTrue('inspect must be auto-approved');

    foreach (['fonts', 'templates', 'examples', 'images', 'media_assets'] as $kind) {
        expect($opsByClass['Spora\\Plugins\\Typst\\Tools\\TypstResourcesTool'][$kind]['auto_approve'] ?? null)
            ->toBeTrue("resources.{$kind} must be auto-approved");
    }
});

it('requires spora-ai/spora-plugin-typst on the typst-expert agent', function (): void {
    $expert = loadAgentTemplate('typst-expert.json');
    expect($expert['required_plugins'] ?? [])->toContain('spora-ai/spora-plugin-typst');
});

it('documents the list → read → write iteration loop in the system prompt', function (): void {
    // Since v1.1.0 the agent teaches itself the iteration workflow so
    // it doesn't try to `write` a `.typ` from memory. Pin the section
    // header + key bullet so future rewrites don't silently drop it.
    $expert = loadAgentTemplate('typst-expert.json');
    $prompt = (string) ($expert['agent']['system_prompt'] ?? '');

    expect($prompt)->toContain('## 4. Iterate with list → read → write');
    expect($prompt)->toContain('typst_resources(action: "templates", op: "read"');
    expect($prompt)->toContain("Don't write a `.typ` you haven't `read` first");
    expect($prompt)->toContain('auto-approved since v1.1.0');
});

it('documents the media-archive image import workflow in the system prompt', function (): void {
    // Pin the section + action label so future rewrites don't
    // collapse the bridge back into a sub-verb on `images`.
    $expert = loadAgentTemplate('typst-expert.json');
    $prompt = (string) ($expert['agent']['system_prompt'] ?? '');

    expect($prompt)->toContain('## 5. Embed media-archive images');
    expect($prompt)->toContain('action: "media_assets", op: "import"');
    expect($prompt)->toContain('filesystem-relative');
});
