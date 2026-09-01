<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use RuntimeException;
use Spora\Models\MediaAsset;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaType;
use Throwable;

/**
 * Stores Typst-plugin image uploads as `media_assets` rows.
 *
 * Distinct from {@see TypstResourceStore} (fonts + examples) in two ways:
 *
 *  1. **Storage location.** Fonts and examples live as raw files in
 *     plugin-private storage (`<storage>/typst/{fonts,examples}/<id>/`).
 *     Images live as proper `media_assets` rows so the asset_url can be
 *     fed back to ext-typst as `#image("…/api/v1/assets/<uuid>.<ext>")`
 *     and the row participates in the media library's LIST / Versions
 *     UI surface.
 *
 *  2. **Id surface.** Fonts + examples use basenames (no row, no UUID);
 *     images use the canonical uuid-keyed `media_assets.id` so the
 *     cross-references in the chat UI's `MediaEmbed` markdown resolve.
 *
 * The store is a leaf with no DI dependencies beyond `AssetStore` — the
 * AssetStore decides between `data_url` and `local` storage based on
 * payload size, mirroring {@see \Spora\Services\MediaArchive\MediaArchiveIngestPipeline}'s
 * branching.
 */
final class TypstImageStore
{
    /**
     * Maximum upload size (5 MiB). Bigger than the font/example cap
     * (also 5 MiB) but kept consistent for plugin-wide simplicity.
     * Operators with legitimate larger needs can bump this in a
     * follow-up; we don't want a single chat-time upload to fill the
     * storage volume before the admin notices.
     */
    public const MAX_BYTES = 5_242_880;

    /**
     * Whitelisted image MIMEs. ext-typst accepts PNG / JPEG / WebP via
     * the `#image()` builtin; SVG is allowed because Typst can embed
     * vector graphics (and Spora's chat UI sanitiser handles inline
     * SVG via the same `<img src>` path as raster).
     */
    private const ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/svg+xml',
    ];

    private const MIME_TO_EXT = [
        'image/png'     => 'png',
        'image/jpeg'    => 'jpg',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];

    public function __construct(
        private readonly AssetStore $assetStore,
    ) {}

    /**
     * Persist a new image bytes payload and return the freshly
     * minted {@see MediaAsset} row. Caller (the HTTP controller) is
     * responsible for principal-scoped ownership checks before
     * reaching this method.
     *
     * `principal_id` is optional here (nullable on the table, nullable
     * in Spora's principal model — same shape {@see MediaDerivativeService::createNew()}
     * follows). The HTTP layer is the gate that decides whether to
     * pass a principal id; CLI / fixture callers may pass `null`.
     *
     * @param array<string, mixed> $ownership
     */
    public function create(string $bytes, string $mime, array $ownership): MediaAsset
    {
        $mime = strtolower(trim($mime));
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException(sprintf(
                'TypstImageStore: mime "%s" is not allowed (allowed: %s)',
                $mime,
                implode(', ', self::ALLOWED_MIMES),
            ));
        }
        if ($bytes === '') {
            throw new RuntimeException('TypstImageStore: empty payload');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'TypstImageStore: payload exceeds %d bytes',
                self::MAX_BYTES,
            ));
        }
        $principalRaw = $ownership['principal_id'] ?? null;
        $principal = is_int($principalRaw) || is_string($principalRaw) || is_numeric($principalRaw)
            ? (int) $principalRaw
            : null;
        if ($principal !== null && $principal <= 0) {
            throw new RuntimeException('TypstImageStore: principal_id must be a positive integer when supplied');
        }

        $now       = Carbon::now();
        $assetId   = self::generateUuid();
        $userId    = isset($ownership['user_id']) && is_numeric($ownership['user_id'])
            ? (int) $ownership['user_id']
            : null;
        $rawName   = isset($ownership['filename']) && is_string($ownership['filename']) && $ownership['filename'] !== ''
            ? $ownership['filename']
            : null;
        $filename  = $rawName ?? ('typst-image.' . self::MIME_TO_EXT[$mime]);

        // AssetStore decides data_url vs local based on size; the
        // returned AssetReference's `mode` + `token` populate the
        // matching columns on the MediaAsset row below.
        $reference = $this->assetStore->store($bytes, $mime, $filename);

        $asset = new MediaAsset();
        $asset->id            = $assetId;
        $asset->principal_id  = $principal;
        $asset->user_id       = $userId;
        $asset->plugin_slug   = 'spora-plugin-typst';
        $asset->tool_name     = 'typst.image';
        $asset->mime_type     = $mime;
        $asset->media_type    = MediaType::Image->value;
        $asset->byte_size     = strlen($bytes);
        $asset->filename      = $filename;
        $asset->storage_mode  = $reference->mode;
        $asset->asset_token   = $reference->token ?? bin2hex(random_bytes(16));
        $asset->asset_url     = \Spora\Services\MediaArchive\MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $assetId . '.' . self::MIME_TO_EXT[$mime];
        $asset->upload_source = 'plugin';
        $asset->created_at    = $now;
        $asset->updated_at    = $now;

        try {
            Capsule::connection()->transaction(function () use ($asset, $bytes, $reference): void {
                $asset->save();
                if ($reference->mode === 'data_url') {
                    $asset->payload = $bytes;
                    $asset->save();
                }
            });
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'TypstImageStore: failed to persist asset: %s',
                $e->getMessage(),
            ), 0, $e);
        }

        return $asset;
    }

    /**
     * Soft-delete a media_asset by id. Throws when the row is missing
     * OR not owned by `$principalId` — both are 404-equivalents from
     * the controller's perspective (don't leak existence).
     *
     * Deleting the `media_assets` row cascades to the `payload`
     * column on the same table; the local-mode `AssetStore` on disk
     * file is cleaned up by a separate `MediaArchiveService::destroy()`
     * path that we don't import here to keep this service leaf-y.
     */
    public function delete(string $id, int $principalId): void
    {
        $asset = MediaAsset::query()->find($id);
        if ($asset === null) {
            throw new RuntimeException('TypstImageStore: image not found');
        }
        if ((int) $asset->principal_id !== $principalId || $asset->plugin_slug !== 'spora-plugin-typst') {
            throw new RuntimeException('TypstImageStore: image not found');
        }
        try {
            $asset->delete();
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'TypstImageStore: failed to delete: %s',
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * List images visible to a principal — owned by `$principalId`
     * AND plugin_slug='spora-plugin-typst' AND tool_name='typst.image'.
     * The plugin/tool-name filter keeps Media Archive uploads out of
     * the Typst admin UI even when both plugins are installed.
     *
     * @return list<MediaAsset>
     */
    public function listFor(int $principalId): array
    {
        $ids = MediaAsset::query()
            ->where('principal_id', $principalId)
            ->where('plugin_slug', 'spora-plugin-typst')
            ->where('tool_name', 'typst.image')
            ->orderBy('created_at', 'desc')
            ->pluck('id');
        $out = [];
        foreach ($ids as $id) {
            $asset = MediaAsset::query()->find((string) $id);
            if ($asset instanceof MediaAsset) {
                $out[] = $asset;
            }
        }
        return $out;
    }

    public static function isAllowedMime(string $mime): bool
    {
        return in_array(strtolower(trim($mime)), self::ALLOWED_MIMES, true);
    }

    /**
     * Generate a UUIDv4 — same canonical format as {@see MediaDerivativeService::generateUuid()}
     * so cross-table lookups stay coherent.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
