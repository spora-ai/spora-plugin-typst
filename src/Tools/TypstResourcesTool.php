<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;
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
 * Per-op sub-action `op: list | write | delete | read` selects the verb.
 * For `list`, no extra params are needed. For `write`, `name` and
 * `content` are required (text bytes — for binary uploads use the
 * admin panel's typst/images endpoint instead). For `delete` and
 * `read`, only `name` is required.
 *
 * `read` returns the resource's bytes so the LLM can iterate:
 * `list → read → modify → write → render` is the canonical edit
 * loop on a `.typ` template or example. Text kinds (`templates`,
 * `examples`) inline the bytes; binary kinds (`fonts`, `images`)
 * return base64 under `data.content_base64`. Tier-1 (skill-shipped)
 * rows are readable too — `TypstResourceStore::read()` falls back
 * from tier-2 to tier-1 — so the LLM can fetch the bundled baseline
 * before overwriting it.
 *
 * The plugin-shipped tier-1 resources (Inter OFL fonts, the report
 * template, the showcase example) are visible to `list` and `read`
 * but cannot be `delete`d — deletion is rejected for tier-1 rows by
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
    private const TOOL_PREFIX = 'typst_resources: ';

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $action = $this->resolveAction($arguments);
        $op     = strtolower(trim((string) ($arguments['op'] ?? 'list')));

        if (!in_array($op, ['list', 'write', 'delete', 'read'], true)) {
            return new ToolResult(false, sprintf(
                'typst_resources: unknown op "%s" (expected: list, write, delete, read)',
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
        // per-request pattern the HTTP controllers use.
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
        return match ($op) {
            'list'  => $this->listResources($paths, $kind),
            'write' => $this->writeResource($paths, $kind, $arguments),
            'read'  => $this->readResource($paths, $kind, $arguments),
            default => $this->deleteResource($paths, $kind, $arguments),
        };
    }

    private function dispatchImage(TypstResourcePaths $paths, string $op, array $arguments): ToolResult
    {
        return match ($op) {
            'list'  => $this->listImages($paths),
            'write' => $this->writeImage($paths, $arguments),
            'read'  => $this->readImage($paths, $arguments),
            default => $this->deleteImage($paths, $arguments),
        };
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
            return new ToolResult(false, self::TOOL_PREFIX . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: wrote %d bytes to %s', strlen($content), $path),
            data: ['path' => $path, 'name' => $name, 'kind' => $kind, 'size' => strlen($content)],
        );
    }

    /**
     * Read the resource bytes for the LLM to iterate on (`list →
     * read → modify → write → render`). Tier-1 + tier-2 are visible
     * together — {@see TypstResourceStore::read()} prefers tier-2
     * and falls back to tier-1 — so the LLM can fetch the bundled
     * baseline before overwriting it.
     *
     * Text kinds (`templates`, `examples`) inline the bytes;
     * `fonts` returns base64 since the binary would corrupt the
     * tool-result transport. Reads are bounded by the same
     * {@see TypstResourceStore::MAX_BYTES} cap that `write`
     * enforces — no asset in tier-2 can exceed 5 MiB, so any
     * successful read fits inline.
     */
    private function readResource(TypstResourcePaths $paths, string $kind, array $arguments): ToolResult
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '') {
            return new ToolResult(false, 'typst_resources: `name` is required for op=read');
        }
        try {
            TypstResourcePaths::assertValidKind($kind);
            $bytes = (new TypstResourceStore($paths))->read($kind, $name);
        } catch (Throwable $e) {
            return new ToolResult(false, self::TOOL_PREFIX . $e->getMessage());
        }
        return $bytes === null
            ? new ToolResult(false, sprintf(
                'typst_resources: %s/%s not found (searched tier-2 then tier-1 under the calling principal)',
                $kind,
                $name,
            ))
            : $this->formatReadResult($kind, $name, $bytes);
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
            return new ToolResult(false, self::TOOL_PREFIX . $e->getMessage());
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
            return new ToolResult(false, self::TOOL_PREFIX . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: wrote %d bytes to %s', strlen($content), $row['name']),
            data: ['name' => $row['name'], 'size' => $row['size']],
        );
    }

    /**
     * Image read mirrors {@see readResource()} but uses
     * {@see TypstImageStore::read()} (binary-only) and always returns
     * base64. Images are principal-only — there is no tier-1 image
     * fallback by design.
     */
    private function readImage(TypstResourcePaths $paths, array $arguments): ToolResult
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '') {
            return new ToolResult(false, 'typst_resources: `name` is required for op=read');
        }
        try {
            $bytes = (new TypstImageStore($paths))->read($name);
        } catch (Throwable $e) {
            return new ToolResult(false, self::TOOL_PREFIX . $e->getMessage());
        }
        return $bytes === null
            ? new ToolResult(false, sprintf(
                'typst_resources: image/%s not found under the calling principal',
                $name,
            ))
            : $this->formatReadResult('image', $name, $bytes);
    }

    /**
     * Format a read result for the LLM. Text kinds inline the bytes
     * after a small header so the LLM can recognise the source vs
     * its own reasoning; binary kinds return the bytes base64 in
     * `data.content_base64` so a non-text payload can't corrupt the
     * tool-result transport.
     *
     * `$kind` is the singular resource kind (`font`, `template`,
     * `example`, `image`) — the same shape {@see TypstResourceStore::read()}
     * and {@see TypstImageStore::read()} take. The binary branch
     * matches `font` and `image`; templates and examples are text.
     */
    private function formatReadResult(string $kind, string $name, string $bytes): ToolResult
    {
        $size  = strlen($bytes);
        $isBin = $kind === 'font' || $kind === 'image';
        $kindPlural = match ($kind) {
            'font'     => 'fonts',
            'template' => 'templates',
            'example'  => 'examples',
            'image'    => 'images',
            default    => throw new \Spora\Plugins\Typst\Exceptions\TypstRuntimeException(sprintf(
                'typst_resources: formatReadResult called with unknown kind "%s"',
                $kind,
            )),
        };

        if ($isBin) {
            return ToolResult::ok(
                sprintf(
                    'Binary %s/%s (%d bytes); base64 payload in data.content_base64.',
                    $kindPlural,
                    $name,
                    $size,
                ),
                [
                    'name'           => $name,
                    'kind'           => $kindPlural,
                    'byte_size'      => $size,
                    'encoding'       => 'base64',
                    'content_base64' => base64_encode($bytes),
                ],
            );
        }

        $header = sprintf('Source of %s/%s (%d bytes):', $kindPlural, $name, $size);
        return ToolResult::ok(
            $header . "\n\n" . $bytes,
            [
                'name'      => $name,
                'kind'      => $kindPlural,
                'byte_size' => $size,
                'encoding'  => 'utf-8',
            ],
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
            return new ToolResult(false, self::TOOL_PREFIX . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: deleted image/%s', $name),
            data: ['name' => $name],
        );
    }
}
