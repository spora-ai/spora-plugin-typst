<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateValidator;

// BASE_PATH is defined in tests/Pest.php and points at the plugin
// root (dirname of __DIR__ there). Resolving the agent-templates
// directory against it keeps the path stable even if the test
// file ever moves deeper in the tree.
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

/**
 * Every agent template shipped in `agent-templates/` must validate
 * cleanly against {@see AgentTemplateValidator}. The directory
 * previously contained an `assistant.json` that violated the schema
 * (`agent.allow_continuation` is not in the validator's allow-list,
 * and `metadata.archetype: "typesetting"` + `metadata.category:
 * "creative"` are not in their respective enums). The expert is a
 * strict superset of that role and ships the only template now.
 */
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
    // The previous typst-assistant template was a general-purpose
    // profile that has been superseded by typst-expert (a strict
    // superset: same tools + approval semantics, plus SkillTool
    // wired to the typst skill and a two-artefact teaser/PDF
    // workflow). Keeping two profiles doubled the template surface
    // to maintain without giving operators a meaningfully different
    // choice, so the assistant has been removed.
    $files = array_map(
        static fn(string $path): string => basename($path),
        glob(AGENT_TEMPLATES_DIR . '/*.json') ?: [],
    );
    expect($files)->toBe(['typst-expert.json']);

    $expert = loadAgentTemplate('typst-expert.json');
    expect($expert['id'])->toBe('typst-expert');

    // The expert must declare a semver version and a non-empty
    // system_prompt — the validator only WARNs on a missing
    // prompt, so we pin the contract explicitly.
    expect($expert['version'])->toMatch('/^[0-9]+\.[0-9]+\.[0-9]+([+-].+)?$/');
    expect(trim((string) $expert['agent']['system_prompt']))->not->toBeEmpty();
});

it('wires SkillTool with the typst skill allowed on the typst-expert agent', function (): void {
    // The activation pattern matches
    // spora-plugin-openai-image/agent-templates/image-agent.json —
    // the agent enables SkillTool and lists the slugs it may load,
    // so the system_prompt's "skill(action: read, name: typst)"
    // directive is enforced server-side by SkillTool's allowed
    // check rather than gated by the skill's `allowedByDefault`.
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
    // Operators toggling the expert see the same per-operation
    // approval surface as the old assistant: render requires
    // approval; inspect and all four resources ops are auto-approved.
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

    // Render requires approval; inspect is auto-approved.
    expect($opsByClass['Spora\\Plugins\\Typst\\Tools\\TypstCompileTool']['render']['auto_approve'] ?? null)
        ->toBeFalse('render must require approval');
    expect($opsByClass['Spora\\Plugins\\Typst\\Tools\\TypstCompileTool']['inspect']['auto_approve'] ?? null)
        ->toBeTrue('inspect must be auto-approved');

    // All four resources ops are auto-approved.
    foreach (['fonts', 'templates', 'examples', 'images'] as $kind) {
        expect($opsByClass['Spora\\Plugins\\Typst\\Tools\\TypstResourcesTool'][$kind]['auto_approve'] ?? null)
            ->toBeTrue("resources.{$kind} must be auto-approved");
    }
});

it('requires spora-ai/spora-plugin-typst on the typst-expert agent', function (): void {
    // Missing plugin warnings are non-fatal but operators should
    // see this agent listed under the right plugin.
    $expert = loadAgentTemplate('typst-expert.json');
    expect($expert['required_plugins'] ?? [])->toContain('spora-ai/spora-plugin-typst');
});
