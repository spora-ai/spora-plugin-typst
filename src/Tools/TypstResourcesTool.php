<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Spora\Plugins\Typst\Services\TypstImageImporter;
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
 * examples (text-shaped, served by {@see TypstResourceStore}),
 * images (binary, served by {@see TypstImageStore}), and the
 * Media-Archive-to-image-library bridge (`media_assets` operation).
 *
 * Each of the four kinds (`fonts` / `templates` / `examples` /
 * `images`) is a separate `#[ToolOperation]`, so the LLM-facing
 * schema lists one row per kind. `media_assets` is a peer to those
 * four rather than a verb on `images` so the orchestrator routes the
 * call to a single code path with a fixed arg shape (`asset_id` +
 * optional `name`); see {@see importImage()}.
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
 *
 * The `mediaAssetReader` closure is an indirection into the host's
 * `final` {@see MediaAssetReader}; the DI binding
 * ({@see \Spora\Plugins\Typst\TypstPlugin::onContainerBuilding()})
 * wraps the autowired reader so the plugin stays decoupled from the
 * core class. `null` means the import op is unavailable; production
 * never sees `null` because DI auto-injects.
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
    description: 'Manage per-principal Typst image resources. list: see visible images. write: upload a new image (text bytes — for binary uploads use the admin panel\'s typst/images endpoint instead). read: fetch an image\'s bytes (base64). delete: remove a tier-2 image.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'media_assets',
    description: 'Copy a Media Archive asset into the principal\'s image library so a follow-up #image("...") can resolve it. The Media Archive\'s /api/v1/assets/<uuid>.<ext> URL does NOT work in #image() directly — ext-typst resolves paths filesystem-relative against the principal\'s storage root, so this op is the bridge. Use `op: "import"` with `asset_id` (UUID; the asset must be visible to the caller) and an optional `name` (defaults to the source asset\'s filename). Mime allowlist: image/png, image/jpeg, image/webp, image/svg+xml; byte size cap 5 MiB.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'op',
    type: 'string',
    description: 'Sub-action: list (default for the four kind actions) | write | delete | read. For action="media_assets", op must be "import".',
    required: false,
    enum: ['list', 'write', 'delete', 'read', 'import'],
)]
#[ToolParameter(
    name: 'name',
    type: 'string',
    description: 'Resource basename (required for write/delete/read; ignored for list; optional for media_assets import — falls back to the source asset\'s filename). Allowed: A-Z a-z 0-9 . _ -',
    required: false,
)]
#[ToolParameter(
    name: 'content',
    type: 'string',
    description: 'UTF-8 file contents for op=write. For binary uploads, base64-encode and pre-decode here (the tool only handles text inline).',
    required: false,
)]
#[ToolParameter(
    name: 'asset_id',
    type: 'string',
    description: 'Media Archive UUID (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx, with optional .ext) for action="media_assets". Required. The asset must be visible to the caller; mime must be in the image allowlist (image/png, image/jpeg, image/webp, image/svg+xml); byte size must be ≤ 5 MiB.',
    required: false,
)]
final class TypstResourcesTool extends AbstractTypstTool
{
    private const TOOL_PREFIX = 'typst_resources: ';

    private readonly TypstImageImporter $importer;

    public function __construct(
        TypstWorldFactory $worldFactory,
        ?TypstImageImporter $importer = null,
    ) {
        parent::__construct($worldFactory);
        $this->importer = $importer ?? new TypstImageImporter();
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

        if (!in_array($op, ['list', 'write', 'delete', 'read', 'import'], true)) {
            return new ToolResult(false, sprintf(
                'typst_resources: unknown op "%s" (expected: list, write, delete, read, import)',
                $op,
            ));
        }

        // Schema accepts the union of verbs; this filter rejects
        // per-action nonsensical pairings (e.g. images + import).
        $opError = $this->validateOpForAction($action, $op);
        if ($opError !== null) {
            return new ToolResult(false, $opError);
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
            'fonts'        => $this->dispatchResource($paths, 'font', $op, $arguments),
            'templates'    => $this->dispatchResource($paths, 'template', $op, $arguments),
            'examples'     => $this->dispatchResource($paths, 'example', $op, $arguments),
            'images'       => $this->dispatchImage($paths, $op, $arguments),
            'media_assets' => $this->importImage($paths, $arguments, $userId),
            default        => new ToolResult(false, sprintf(
                'typst_resources: unknown action "%s" (expected: fonts, templates, examples, images, media_assets)',
                $action,
            )),
        };
    }

    public function describeAction(array $arguments): string
    {
        $action  = $this->resolveAction($arguments);
        $op      = strtolower((string) ($arguments['op'] ?? 'list'));
        $assetId = (string) ($arguments['asset_id'] ?? '');
        $name    = (string) ($arguments['name'] ?? '');
        // Use the source UUID for `media_assets/op=import` (the
        // meaningful identifier); fall back to `name` for the
        // filesystem kinds, then to `asset_id` for both. Truncate to
        // 12 chars so the approval row stays narrow.
        if ($action === 'media_assets') {
            $tag = $assetId;
        } elseif ($name !== '') {
            $tag = $name;
        } else {
            $tag = $assetId;
        }
        return sprintf('Typst resources %s/%s%s', $action, $op, $tag !== '' ? ':' . substr($tag, 0, 12) : '');
    }

    /**
     * Returns null for unknown actions — the dispatcher's `default`
     * arm owns that error message.
     */
    private function validateOpForAction(string $action, string $op): ?string
    {
        $validOps = match ($action) {
            'fonts', 'templates', 'examples', 'images' => ['list', 'write', 'delete', 'read'],
            'media_assets'                             => ['import'],
            default                                    => null,
        };
        if ($validOps === null) {
            return null;
        }
        if (!in_array($op, $validOps, true)) {
            return sprintf(
                'typst_resources: action "%s" does not accept op "%s" (expected one of: %s)',
                $action,
                $op,
                implode(', ', $validOps),
            );
        }
        return null;
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

    /**
     * Copy a Media Archive asset into the principal's image library.
     * ext-typst treats every `#image()` path as filesystem-relative,
     * so the canonical `/api/v1/assets/<uuid>.<ext>` URL doesn't
     * resolve; the image-library URL does, because we just wrote the
     * file under the principal's `template_dir`. Importing is the
     * bridge.
     */
    private function importImage(
        TypstResourcePaths $paths,
        array $arguments,
        ?int $userId,
    ): ToolResult {
        return $this->importer->import($paths, $arguments, $userId);
    }
}
