<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Manage the Typst plugin's per-principal resources: fonts, templates,
 * examples (text-shaped, served by {@see TypstResourceStore}) and
 * images (binary, served by {@see TypstImageStore}). Each resource
 * kind is a separate `#[ToolOperation]`, so the LLM-facing schema
 * lists one row per kind instead of a single row with a kind verb.
 *
 * Per-op sub-action `op: list | write | delete` selects the verb.
 * For `list`, no extra params are needed. For `write`, `name` and
 * `content` are required (text bytes — for binary uploads use the
 * admin panel's typst/images endpoint instead). For `delete`, only
 * `name` is required.
 *
 * The plugin-shipped tier-1 resources (Inter OFL fonts, the report
 * template, the showcase example) are visible to `list` but cannot
 * be `delete`d — deletion is rejected for tier-1 rows by
 * {@see TypstResourceStore::delete()} and {@see TypstImageStore::delete()}.
 *
 * Basenames are restricted to a conservative charset (the tool
 * rejects anything containing `/`, `\`, or shell metas before it
 * touches disk), and text payloads are capped at
 * {@see TypstResourceStore::MAX_BYTES}.
 */
#[Tool(
    name: 'typst_resources',
    description: 'Manage the Typst plugin\'s per-principal font, template, example, and image resources (list / write / delete per kind).',
    displayName: 'Typst Resources',
    category: 'generation',
    icon: 'paperclip',
)]
#[ToolOperation(
    name: 'fonts',
    description: 'Manage per-principal fonts (.ttf / .otf). list: see what fonts are visible. write: upload a new font (text bytes, capped). delete: remove a tier-2 font.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'templates',
    description: 'Manage per-principal Typst template files. list: see visible templates. write: upload a new template (text bytes). delete: remove a tier-2 template.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'examples',
    description: 'Manage per-principal Typst example snippets. list: see visible examples. write: upload a new example (text bytes). delete: remove a tier-2 example.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'images',
    description: 'Manage per-principal Typst image resources. list: see visible images. write: upload a new image (text bytes — for binary uploads use the admin panel\'s typst/images endpoint instead). delete: remove a tier-2 image.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'op',
    type: 'string',
    description: 'Sub-action: list (default) | write | delete.',
    required: false,
    enum: ['list', 'write', 'delete'],
)]
#[ToolParameter(
    name: 'name',
    type: 'string',
    description: 'Resource basename (required for write/delete; ignored for list). Allowed: A-Z a-z 0-9 . _ -',
    required: false,
)]
#[ToolParameter(
    name: 'content',
    type: 'string',
    description: 'UTF-8 file contents for op=write. For binary uploads, base64-encode and pre-decode here (the tool only handles text inline).',
    required: false,
)]
final class TypstResourcesTool extends AbstractTypstTool
{
    public function __construct(
        TypstWorldFactory $worldFactory,
    ) {
        parent::__construct($worldFactory);
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $action = $this->resolveAction($arguments);
        $op     = strtolower(trim((string) ($arguments['op'] ?? 'list')));

        if (!in_array($op, ['list', 'write', 'delete'], true)) {
            return new ToolResult(false, sprintf(
                'typst_resources: unknown op "%s" (expected: list, write, delete)',
                $op,
            ));
        }

        // Build the resource store scoped to *this* call's principal.
        // The constructor-injected stores were always null-principal
        // (PHP-DI wires the singleton at boot before any request has
        // resolved a principal), so the previous incarnation of this
        // tool would throw on every principal-scoped operation. We
        // now build a fresh `TypstResourcePaths` per call from the
        // orchestrator-supplied `PrincipalContext`, mirroring the
        // per-request `storeForCurrentUser()` pattern the HTTP
        // controllers use.
        $paths = new TypstResourcePaths($this->paths(), $context?->principalId);

        return match ($action) {
            'fonts'     => $this->dispatchResource($paths, 'font', $op, $arguments),
            'templates' => $this->dispatchResource($paths, 'template', $op, $arguments),
            'examples'  => $this->dispatchResource($paths, 'example', $op, $arguments),
            'images'    => $this->dispatchImage($paths, $op, $arguments),
            default     => new ToolResult(false, sprintf(
                'typst_resources: unknown action "%s" (expected: fonts, templates, examples, images)',
                $action,
            )),
        };
    }

    public function describeAction(array $arguments): string
    {
        $action = $this->resolveAction($arguments);
        $op     = strtolower((string) ($arguments['op'] ?? 'list'));
        $name   = (string) ($arguments['name'] ?? '');
        return sprintf('Typst resources %s/%s%s', $action, $op, $name !== '' ? ':' . $name : '');
    }

    private function dispatchResource(TypstResourcePaths $paths, string $kind, string $op, array $arguments): ToolResult
    {
        if ($op === 'list') {
            return $this->listResources($paths, $kind);
        }
        if ($op === 'write') {
            return $this->writeResource($paths, $kind, $arguments);
        }
        return $this->deleteResource($paths, $kind, $arguments);
    }

    private function dispatchImage(TypstResourcePaths $paths, string $op, array $arguments): ToolResult
    {
        if ($op === 'list') {
            return $this->listImages($paths);
        }
        if ($op === 'write') {
            return $this->writeImage($paths, $arguments);
        }
        return $this->deleteImage($paths, $arguments);
    }

    private function listResources(TypstResourcePaths $paths, string $kind): ToolResult
    {
        try {
            TypstResourcePaths::assertValidKind($kind);
        } catch (Throwable $e) {
            return new ToolResult(false, $e->getMessage());
        }

        $rows = (new TypstResourceStore($paths))->list($kind);
        if ($rows === []) {
            return new ToolResult(true, sprintf('typst_resources: no %s resources visible', $kind));
        }
        $lines = [sprintf('typst_resources: %d %s(s) visible', count($rows), $kind)];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '- %s [%s, %d bytes]',
                $row['name'],
                $row['origin'],
                $row['size'],
            );
        }
        return ToolResult::ok(
            content: implode("\n", $lines),
            data: ['resources' => $rows],
        );
    }

    private function writeResource(TypstResourcePaths $paths, string $kind, array $arguments): ToolResult
    {
        $name    = (string) ($arguments['name'] ?? '');
        $content = $arguments['content'] ?? null;
        if ($name === '' || !is_string($content)) {
            return new ToolResult(false, 'typst_resources: `name` and `content` are required for op=write');
        }
        try {
            TypstResourcePaths::assertValidKind($kind);
            $path = (new TypstResourceStore($paths))->write($kind, $name, $content);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_resources: ' . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: wrote %d bytes to %s', strlen($content), $path),
            data: ['path' => $path, 'name' => $name, 'kind' => $kind, 'size' => strlen($content)],
        );
    }

    private function deleteResource(TypstResourcePaths $paths, string $kind, array $arguments): ToolResult
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '') {
            return new ToolResult(false, 'typst_resources: `name` is required for op=delete');
        }
        try {
            TypstResourcePaths::assertValidKind($kind);
            (new TypstResourceStore($paths))->delete($kind, $name);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_resources: ' . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: deleted %s/%s', $kind, $name),
            data: ['name' => $name, 'kind' => $kind],
        );
    }

    private function listImages(TypstResourcePaths $paths): ToolResult
    {
        $rows = (new TypstImageStore($paths))->list();
        if ($rows === []) {
            return new ToolResult(true, 'typst_resources: no images visible');
        }
        $lines = [sprintf('typst_resources: %d image(s) visible', count($rows))];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '- %s [%s, %d bytes]',
                $row['name'],
                $row['mime'],
                $row['size'],
            );
        }
        return ToolResult::ok(
            content: implode("\n", $lines),
            data: ['resources' => $rows],
        );
    }

    private function writeImage(TypstResourcePaths $paths, array $arguments): ToolResult
    {
        $name    = (string) ($arguments['name'] ?? '');
        $content = $arguments['content'] ?? null;
        if ($name === '' || !is_string($content)) {
            return new ToolResult(false, 'typst_resources: `name` and `content` are required for op=write');
        }
        try {
            $row = (new TypstImageStore($paths))->write($content, 'application/octet-stream', $name);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_resources: ' . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: wrote %d bytes to %s', strlen($content), $row['name']),
            data: ['name' => $row['name'], 'size' => $row['size']],
        );
    }

    private function deleteImage(TypstResourcePaths $paths, array $arguments): ToolResult
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '') {
            return new ToolResult(false, 'typst_resources: `name` is required for op=delete');
        }
        try {
            (new TypstImageStore($paths))->delete($name);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_resources: ' . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: deleted image/%s', $name),
            data: ['name' => $name],
        );
    }
}
