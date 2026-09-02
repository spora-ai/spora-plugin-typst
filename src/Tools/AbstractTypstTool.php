<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use InvalidArgumentException;
use RuntimeException;
use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\PrincipalContext;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\ToolParameter;

/**
 * Common helpers for the three Typst plugin tools
 * (`typst_render`, `typst_inspect`, `typst_resources`).
 *
 * Centralises:
 *   - the `action` parameter declaration (the resource tool is a
 *     multi-op dispatcher with a shared parameter schema);
 *   - the source-resolution helper that turns an inline `source`
 *     string OR a `file` asset id into bytes + a {@see MediaAsset}
 *     parent (creating one for inline sources so the natural key on
 *     `media_derivatives` is well-defined);
 *   - the principal-scope check that mirrors
 *     {@see \Spora\Services\MediaArchive\MediaArchiveService::isAssetInPrincipalScope()}
 *     without the 7-parameter DI chain — the rule is small enough
 *     to inline, and reusing it across tools keeps the visible
 *     behaviour in one place.
 *
 * Subclasses call {@see resolveAction()} in `execute()` to get a
 * typed action string and then dispatch on it.
 */
#[ToolParameter(
    name: 'action',
    type: 'string',
    description: 'Which operation to perform: render | inspect | resources_list | resources_write | resources_delete (typst_render and typst_inspect ignore this; typst_resources dispatches on it).',
    required: false,
)]
abstract class AbstractTypstTool extends AbstractTool
{
    public function __construct(
        protected readonly TypstWorldFactory $worldFactory,
        protected readonly TypstResourceStore $resourceStore,
    ) {}

    protected function resolveAction(array $arguments): string
    {
        return strtolower(trim((string) ($arguments['action'] ?? '')));
    }

    /**
     * @return array{bytes: string, parent: MediaAsset}
     */
    protected function resolveSource(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context): array
    {
        $source = $arguments['source'] ?? null;
        $fileId = $arguments['file'] ?? null;

        if (is_string($source) && $source !== '') {
            return [
                'bytes'  => $source,
                'parent' => $this->materialiseInlineSource($source, $agentId, $userId, $context),
            ];
        }
        if (is_string($fileId) && $fileId !== '') {
            return $this->loadAssetSource($fileId, $context, $userId);
        }

        throw new InvalidArgumentException(
            'Typst tool: either `source` (inline string) or `file` (media asset id) is required',
        );
    }

    private function loadAssetSource(string $fileId, ?PrincipalContext $context, ?int $userId): array
    {
        $asset = MediaAsset::query()->find($fileId);
        if ($asset === null) {
            throw new RuntimeException(sprintf('Typst tool: media asset "%s" not found', $fileId));
        }
        if (!$this->assetIsVisibleTo($asset, $context, $userId)) {
            throw new RuntimeException(sprintf('Typst tool: media asset "%s" not visible', $fileId));
        }

        $bytes = match ($asset->storage_mode) {
            'data_url' => is_string($asset->payload) ? $asset->payload : '',
            'local'    => $this->readLocalAsset($asset),
            default    => throw new RuntimeException(sprintf(
                'Typst tool: cannot read storage_mode "%s"',
                (string) $asset->storage_mode,
            )),
        };
        if ($bytes === '') {
            throw new RuntimeException('Typst tool: asset has empty bytes');
        }
        return [
            'bytes'  => $bytes,
            'parent' => $asset,
        ];
    }

    /**
     * Mirrors `MediaArchiveService::isAssetInPrincipalScope()`'s rule:
     * the caller's principal_id must match the asset's, OR the asset's
     * user_id must match the caller's owner user id, OR the asset is
     * attached to an agent in the caller's principal.
     */
    private function assetIsVisibleTo(MediaAsset $asset, ?PrincipalContext $context, ?int $userId): bool
    {
        if ($context === null) {
            return false;
        }
        $principalId = $context->principalId;
        $ownerUserId = $context->ownerUserId ?? $userId;
        if ($principalId <= 0 || $ownerUserId === null) {
            return false;
        }
        if ((int) $asset->user_id === (int) $ownerUserId) {
            return true;
        }
        if ($asset->agent_id === null) {
            return false;
        }
        return Agent::query()
            ->where('id', (int) $asset->agent_id)
            ->where('principal_id', $principalId)
            ->exists();
    }

    private function readLocalAsset(MediaAsset $asset): string
    {
        $token = $asset->asset_token;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Typst tool: asset has no token');
        }
        $ext = \Spora\Services\MediaArchive\MediaArchiveService::extensionForMime($asset->mime_type);
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $paths = new \Spora\Core\Paths($basePath);
        $path = $paths->storage('assets') . '/' . $token . '.' . ($ext ?? 'bin');

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $bytes = file_get_contents($path);
        } finally {
            restore_error_handler();
        }
        return is_string($bytes) ? $bytes : '';
    }

    /**
     * For inline `source`, persist a transient parent row so
     * `MediaDerivativeService::create()` can record the natural-key
     * link `(parent_id, format, producer_plugin, producer_operation)`.
     * The parent is a `text/x-typst` MediaAsset with `storage_mode =
     * data_url` so the producer's read path picks up the source bytes
     * directly without writing a duplicate to disk. The
     * `MediaDerivativeService::create()` will overwrite the row's
     * `mime_type`/`byte_size`/`asset_url`/`media_type` columns for
     * the derivative on the next step, so this parent row carries
     * only the identity bits the FK needs.
     */
    private function materialiseInlineSource(string $source, int $agentId, ?int $userId, ?PrincipalContext $context): MediaAsset
    {
        $id = $this->generateUuid();
        $principalId = $context !== null ? $context->principalId : 0;
        $ownerUserId = $context !== null ? ($context->ownerUserId ?? $userId) : $userId;

        $asset = new MediaAsset();
        $asset->id            = $id;
        $asset->user_id       = $ownerUserId;
        $asset->agent_id      = $agentId > 0 ? $agentId : null;
        $asset->principal_id  = $principalId > 0 ? (int) $principalId : null;
        $asset->plugin_slug   = 'spora-plugin-typst';
        $asset->tool_name     = 'typst.render';
        $asset->mime_type     = 'text/x-typst';
        $asset->media_type    = MediaType::Document->value;
        $asset->byte_size     = strlen($source);
        $asset->filename      = 'inline-source.typ';
        $asset->storage_mode  = 'data_url';
        $asset->asset_token   = bin2hex(random_bytes(16));
        $asset->upload_source = 'tool';
        $asset->payload       = $source;
        $asset->asset_url     = \Spora\Services\MediaArchive\MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.typ';
        $asset->created_at    = \Illuminate\Support\Carbon::now();
        $asset->updated_at    = $asset->created_at;
        $asset->save();

        return $asset;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
