<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use Closure;
use Spora\Models\MediaAsset;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Copy a Media Archive asset into the principal's image library so a
 * follow-up #image("…") in Typst source can resolve it. ext-typst
 * treats every #image() path as filesystem-relative against the
 * principal's `template_dir`, so the canonical
 * `/api/v1/assets/<uuid>.<ext>` URL doesn't resolve; the
 * image-library URL does, because we just wrote the file under that
 * same `template_dir`. Importing is the bridge.
 *
 * The `mediaAssetReader` closure is an indirection into the host's
 * `final` {@see \Spora\Services\MediaArchive\MediaAssetReader}; the
 * DI binding wraps the autowired reader so this service stays
 * decoupled from the core class. `null` means the import op is
 * unavailable; production never sees `null` because DI auto-injects.
 */
final class TypstImageImporter
{
    private const TOOL_PREFIX = 'typst_resources: ';

    /**
     * @var (Closure(string $id, ?int $userId): ?array{status: 'data_url'|'local'|'external', bytes?: string, mime?: string, sourceUrl?: string}|null)|null
     */
    private readonly ?Closure $mediaAssetReader;

    public function __construct(?Closure $mediaAssetReader = null)
    {
        $this->mediaAssetReader = $mediaAssetReader;
    }

    public function import(TypstResourcePaths $paths, array $arguments, ?int $userId): ToolResult
    {
        $assetId = $this->resolveImportAssetId($arguments);
        if ($assetId instanceof ToolResult) {
            return $assetId;
        }

        $payload = $this->fetchImportPayload($assetId, $userId);
        if ($payload instanceof ToolResult) {
            return $payload;
        }

        return $this->writeImportedImage($paths, $arguments, $assetId, $payload);
    }

    /**
     * @return non-empty-string|ToolResult
     */
    private function resolveImportAssetId(array $arguments): string|ToolResult
    {
        $assetId = (string) ($arguments['asset_id'] ?? '');
        if ($assetId === '') {
            return new ToolResult(false, self::TOOL_PREFIX . '`asset_id` is required for op=import');
        }
        if (preg_match(
            '/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:\.[A-Z0-9]+)?$/i',
            $assetId,
            $m,
        ) !== 1) {
            return new ToolResult(false, sprintf(
                '%sinvalid asset_id "%s" (expected 36-char UUID, optional .ext stripped)',
                self::TOOL_PREFIX,
                $assetId,
            ));
        }
        return $m[1];
    }

    /**
     * @return array{status: string, bytes?: string, mime?: string, sourceUrl?: string}|ToolResult
     */
    private function fetchImportPayload(string $assetId, ?int $userId): array|ToolResult
    {
        if ($this->mediaAssetReader === null) {
            return new ToolResult(false, self::TOOL_PREFIX . 'import is not configured (MediaAssetReader not wired)');
        }
        $payload = ($this->mediaAssetReader)($assetId, $userId);
        if ($payload === null) {
            return new ToolResult(false, sprintf(
                '%smedia asset %s not found in the Media Archive, or not accessible to this caller',
                self::TOOL_PREFIX,
                $assetId,
            ));
        }
        return $payload;
    }

    private function writeImportedImage(
        TypstResourcePaths $paths,
        array $arguments,
        string $assetId,
        array $payload,
    ): ToolResult {
        $shape = $this->validateImportShape($assetId, $payload);
        if ($shape !== null) {
            return $shape;
        }

        $bytes  = (string) ($payload['bytes'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $size   = $this->validateImportSize($assetId, $bytes, $status);
        if ($size !== null) {
            return $size;
        }

        return $this->writeImportBytes($paths, $arguments, $assetId, $payload);
    }

    private function validateImportShape(string $assetId, array $payload): ?ToolResult
    {
        $status = (string) ($payload['status'] ?? '');
        if ($status === 'external') {
            return new ToolResult(false, sprintf(
                '%smedia asset %s is stored externally (storage_mode=external) and '
                . 'has no Spora-side bytes; ask the source plugin to re-ingest with bytes, '
                . 'or upload via the Images tab.',
                self::TOOL_PREFIX,
                $assetId,
            ));
        }
        $mime = strtolower(trim((string) ($payload['mime'] ?? '')));
        if (!TypstImageStore::isAllowedMime($mime)) {
            return new ToolResult(false, sprintf(
                '%smedia asset %s has mime "%s", which is not a supported image '
                . '(allowed: image/png, image/jpeg, image/webp, image/svg+xml)',
                self::TOOL_PREFIX,
                $assetId,
                $mime,
            ));
        }
        return null;
    }

    private function validateImportSize(string $assetId, string $bytes, string $status): ?ToolResult
    {
        if ($bytes === '') {
            return new ToolResult(false, sprintf(
                '%smedia asset %s has zero readable bytes (storage_mode=%s)',
                self::TOOL_PREFIX,
                $assetId,
                $status,
            ));
        }
        if (strlen($bytes) > TypstImageStore::MAX_BYTES) {
            return new ToolResult(false, sprintf(
                '%smedia asset %s (%d bytes) exceeds the %d-byte image cap; '
                . 'reduce the asset (e.g. via media.create_derivative) or upload via the Images tab.',
                self::TOOL_PREFIX,
                $assetId,
                strlen($bytes),
                TypstImageStore::MAX_BYTES,
            ));
        }
        return null;
    }

    private function writeImportBytes(
        TypstResourcePaths $paths,
        array $arguments,
        string $assetId,
        array $payload,
    ): ToolResult {
        $mime  = strtolower(trim((string) ($payload['mime'] ?? '')));
        $bytes = (string) ($payload['bytes'] ?? '');
        $name  = $this->resolveImportName($arguments, $assetId);

        try {
            $row = (new TypstImageStore($paths))->write(
                $bytes,
                $mime,
                $name !== '' ? $name : null,
            );
        } catch (Throwable $e) {
            return new ToolResult(false, self::TOOL_PREFIX . $e->getMessage());
        }

        return ToolResult::ok(
            sprintf('%simported media asset %s as image/%s (%d bytes)', self::TOOL_PREFIX, $assetId, $row['name'], $row['size']),
            [
                'asset_id'      => $assetId,
                'name'          => $row['name'],
                'url'           => '/api/v1/typst/images/' . rawurlencode($row['name']),
                'mime'          => $row['mime'],
                'size'          => $row['size'],
                'renamed'       => (bool) $row['renamed'],
                'original_name' => $row['original_name'],
            ],
        );
    }

    /**
     * The closure seam doesn't carry the source `filename` (its
     * signature mirrors `MediaAssetReader::readAsset()`), so resolve
     * it here after the ownership check has already passed.
     */
    private function resolveImportName(array $arguments, string $assetId): string
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name !== '') {
            return $name;
        }
        $source = MediaAsset::query()->find($assetId);
        return ($source !== null && is_string($source->filename)) ? $source->filename : '';
    }
}
