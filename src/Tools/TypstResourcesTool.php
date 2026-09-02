<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Read/write/delete the principal's tier-2 resources (fonts,
 * templates, and examples) used by Typst renders. The
 * plugin-shipped tier-1 resources (Inter OFL fonts, invoice
 * template, headings example) are visible to `list`/`read` but
 * cannot be deleted.
 *
 * Multi-op dispatcher with a single shared parameter schema:
 *   - `action=resources_list`   — list fonts/templates/examples the principal can see
 *   - `action=resources_write`  — upload a new tier-2 resource
 *   - `action=resources_delete` — remove a tier-2 resource
 *
 * The tool's storage contract mirrors {@see TypstResourceStore}:
 * basenames are restricted to a conservative charset (the tool
 * rejects anything containing `/`, `\`, or shell metas before it
 * touches disk), payloads are capped at {@see TypstResourceStore::MAX_BYTES}.
 *
 * Returns machine-readable listings (action=list) or a confirmation
 * (action=write/delete). For upload/delete from the admin panel
 * use {@see \Spora\Plugins\Typst\Http\TypstFontController},
 * {@see \Spora\Plugins\Typst\Http\TypstTemplateController}, and
 * {@see \Spora\Plugins\Typst\Http\TypstExampleController} instead —
 * they enforce the same invariants over HTTP.
 *
 * `image` resources are managed through a different store
 * ({@see \Spora\Plugins\Typst\Services\TypstImageStore}) and aren't
 * reachable via this tool — upload them via the admin panel's
 * Images tab or `POST /api/v1/typst/images`.
 */
#[Tool(
    name: 'typst_resources',
    description: 'Manage the Typst plugin\'s per-principal font, template, and example resources (list / write / delete).',
)]
#[ToolParameter(
    name: 'kind',
    type: 'string',
    description: 'Resource kind: font | template | example.',
    required: true,
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
    description: 'UTF-8 file contents for action=write. For binary uploads, base64-encode and pre-decode here (the tool only handles text inline).',
    required: false,
)]
final class TypstResourcesTool extends AbstractTypstTool
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $kind = (string) ($arguments['kind'] ?? '');
        try {
            TypstResourcePaths::assertValidKind($kind);
        } catch (Throwable $e) {
            return new ToolResult(false, $e->getMessage());
        }

        return match ($this->resolveAction($arguments)) {
            'resources_list'   => $this->listResources($kind),
            'resources_write'  => $this->writeResource($kind, $arguments),
            'resources_delete' => $this->deleteResource($kind, $arguments),
            default            => new ToolResult(false, 'typst_resources: unknown action. Expected one of: resources_list, resources_write, resources_delete.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $action = $this->resolveAction($arguments);
        $kind   = (string) ($arguments['kind'] ?? '?');
        $name   = (string) ($arguments['name'] ?? '');
        return sprintf('Typst resources %s (%s%s)', $action, $kind, $name !== '' ? ':' . $name : '');
    }

    private function listResources(string $kind): ToolResult
    {
        $rows = $this->resourceStore->list($kind);
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

    private function writeResource(string $kind, array $arguments): ToolResult
    {
        $name    = (string) ($arguments['name'] ?? '');
        $content = $arguments['content'] ?? null;
        if ($name === '' || !is_string($content)) {
            return new ToolResult(false, 'typst_resources: `name` and `content` are required for resources_write');
        }
        try {
            $path = $this->resourceStore->write($kind, $name, $content);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_resources: ' . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: wrote %d bytes to %s', strlen($content), $path),
            data: ['path' => $path, 'name' => $name, 'kind' => $kind, 'size' => strlen($content)],
        );
    }

    private function deleteResource(string $kind, array $arguments): ToolResult
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '') {
            return new ToolResult(false, 'typst_resources: `name` is required for resources_delete');
        }
        try {
            $this->resourceStore->delete($kind, $name);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_resources: ' . $e->getMessage());
        }
        return ToolResult::ok(
            content: sprintf('typst_resources: deleted %s/%s', $kind, $name),
            data: ['name' => $name, 'kind' => $kind],
        );
    }
}
