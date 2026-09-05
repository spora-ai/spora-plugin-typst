<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstInvalidArgumentException;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
use Spora\Plugins\Typst\Services\TypstFilename;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\PrincipalContext;
use Spora\Tools\AbstractTool;

/**
 * Common helpers for the two Typst plugin tools (`typst_compile` and
 * `typst_resources`).
 *
 * Centralises:
 *   - the `action` discriminator dispatch helper (the trait-owned
 *     `#[ToolOperation]` declarations on each subclass synthesise
 *     this property; we just read it back via the parent trait's
 *     `getOperationName()`);
 *   - the source-resolution helper that turns an inline `source`
 *     string OR a `file` asset id into bytes + a {@see MediaAsset}
 *     parent (creating one for inline sources so the natural key on
 *     `media_derivatives` is well-defined);
 *   - the principal-scope check that mirrors
 *     {@see MediaArchiveService::isAssetInPrincipalScope()}
 *     without the 7-parameter DI chain — the rule is small enough
 *     to inline, and reusing it across tools keeps the visible
 *     behaviour in one place.
 *   - a {@see \Spora\Core\Paths} factory (`paths()`) — the
 *     `TypstResourcesTool` needs to build per-call
 *     {@see \Spora\Plugins\Typst\Services\TypstResourcePaths}
 *     instances scoped to the call's principal, but `Paths` is a
 *     stateless value object constructed from `BASE_PATH`; sharing
 *     the construction keeps the tools in lockstep with the HTTP
 *     controllers.
 *
 * Subclasses declare their `#[ToolOperation]` set on themselves;
 * `resolveAction()` returns the discriminator value the LLM sent.
 */
abstract class AbstractTypstTool extends AbstractTool
{
    public function __construct(
        protected readonly TypstWorldFactory $worldFactory,
    ) {}

    /**
     * Lazily constructs a {@see \Spora\Core\Paths} rooted at the
     * skeleton's `BASE_PATH`. Mirrors the same construction the HTTP
     * controllers use (`TypstFontController::paths()`), so the
     * tool path and the HTTP path agree on `<storage>/typst/`.
     */
    protected function paths(): \Spora\Core\Paths
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return new \Spora\Core\Paths($basePath);
    }

    protected function resolveAction(array $arguments): string
    {
        return strtolower(trim($this->getOperationName($arguments)));
    }

    /**
     * Resolve the raw source bytes for an inspect call. No DB writes
     * happen on this path — the inspector only needs the bytes to
     * run `inspectString()`, and materialising a `MediaAsset` parent
     * the tool never uses was leaving orphan `inline-source.typ`
     * rows in `media_assets`.
     *
     * Inline `source` is returned as-is; `file=<id>` is loaded via
     * {@see loadAssetSource()} but the `parent` field is dropped
     * from the result so callers cannot accidentally pass it to a
     * write path.
     *
     * @return array{bytes: string, parent: ?MediaAsset}
     */
    protected function resolveSourceBytes(array $arguments, ?PrincipalContext $context, ?int $userId): array
    {
        $source = $arguments['source'] ?? null;
        $fileId = $arguments['file'] ?? null;

        if (is_string($source) && $source !== '') {
            return ['bytes' => $source, 'parent' => null];
        }
        if (is_string($fileId) && $fileId !== '') {
            $loaded = $this->loadAssetSource($fileId, $context, $userId);
            return ['bytes' => $loaded['bytes'], 'parent' => null];
        }

        throw new TypstInvalidArgumentException(
            'Typst tool: either `source` (inline string) or `file` (media asset id) is required',
        );
    }

    /**
     * Resolve the source bytes + parent `MediaAsset` for a render
     * call. Inline `source` uses the LLM-supplied `filename` (or an
     * auto-generated `inline-YYYYMMDD-HHMMSS-XXXX.typ` when omitted)
     * as the playground pool row name; `file=<id>` reuses the
     * existing parent and ignores `filename` if also supplied.
     *
     * Auto-defaulting the filename was added because LLMs
     * habitually reach for `file` when they mean a basename and the
     * schema's `file` is reserved for media asset UUIDs. Forcing the
     * LLM to invent a basename just to render inline source was a
     * straight jacket — the `inspect` action already accepts inline
     * `source` with no name, and `render` should be no harder.
     *
     * @return array{bytes: string, parent: MediaAsset}
     */
    protected function resolveSourceForRender(
        array $arguments,
        int $agentId,
        ?int $userId,
        ?PrincipalContext $context,
    ): array {
        $source = $arguments['source'] ?? null;
        $fileId = $arguments['file'] ?? null;

        if (is_string($fileId) && $fileId !== '') {
            return $this->loadAssetSource($fileId, $context, $userId);
        }

        if (is_string($source) && $source !== '') {
            $rawName = $arguments['filename'] ?? null;
            if (!is_string($rawName) || trim($rawName) === '') {
                $rawName = $this->autoFilename();
            }
            $name = TypstFilename::sanitise($rawName, 'inline.typ');
            $parent = $this->materialiseNamedInlineSource($source, $name, $agentId, $userId, $context);
            return ['bytes' => $source, 'parent' => $parent];
        }

        throw new TypstInvalidArgumentException(
            'Typst tool: either `source` (inline string) or `file` (media asset id) is required',
        );
    }

    /**
     * Build a deterministic-but-unique-enough playground name for a
     * render call where the LLM didn't supply `filename`. Format is
     * `inline-YYYYMMDD-HHMMSS-XXXX.typ` where XXXX is 4 random hex
     * chars — human-readable, sortable, and short enough to fit in
     * the playground picker's narrow column. Each render inserts a
     * sibling row even on collision (see the materialise helper's
     * docblock), so over-the-wire uniqueness within the same second
     * relies on the random suffix.
     */
    private function autoFilename(): string
    {
        return sprintf('inline-%s-%s.typ', date('Ymd-His'), bin2hex(random_bytes(2)));
    }

    private function loadAssetSource(string $fileId, ?PrincipalContext $context, ?int $userId): array
    {
        $asset = MediaAsset::query()->find($fileId);
        if ($asset === null) {
            throw new TypstRuntimeException(sprintf('Typst tool: media asset "%s" not found', $fileId));
        }
        if (!$this->assetIsVisibleTo($asset, $context, $userId)) {
            throw new TypstRuntimeException(sprintf('Typst tool: media asset "%s" not visible', $fileId));
        }

        $bytes = match ($asset->storage_mode) {
            'data_url' => is_string($asset->payload) ? $asset->payload : '',
            'local'    => $this->readLocalAsset($asset),
            default    => throw new TypstRuntimeException(sprintf(
                'Typst tool: cannot read storage_mode "%s"',
                (string) $asset->storage_mode,
            )),
        };
        if ($bytes === '') {
            throw new TypstRuntimeException('Typst tool: asset has empty bytes');
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
        return $this->assetVisibility($asset, $ownerUserId, $principalId);
    }

    private function assetVisibility(MediaAsset $asset, int $ownerUserId, int $principalId): bool
    {
        if ((int) $asset->user_id === $ownerUserId) {
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
            throw new TypstRuntimeException('Typst tool: asset has no token');
        }
        $ext = MediaArchiveService::extensionForMime($asset->mime_type);
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
     * For inline `source` on a render call, persist a named parent
     * row in the playground pool so the operator can find the row
     * again from the file picker, and so a second render with the
     * same `filename` produces a sibling row instead of overwriting
     * the source in place.
     *
     * The parent is a `text/x-typst` MediaAsset with
     * `storage_mode = data_url` so the producer's read path picks up
     * the source bytes directly without writing a duplicate to
     * disk. The `MediaDerivativeService::create()` step will
     * overwrite the row's `mime_type`/`byte_size`/`asset_url`/
     * `media_type` columns for the derivative on the next step, so
     * the parent row carries only the identity bits the FK needs.
     *
     * Always INSERTs — the previous `upsertInlineSource()` shape
     * collapsed identical filenames onto a single row, which made
     * "playground.typ" a single shared scratchpad across sessions.
     * Re-rendering the same parent still refreshes derivatives in
     * place via the `(parent_id, format, producer_plugin,
     * producer_operation)` natural key on `media_derivatives`, so
     * no idempotency is lost.
     */
    private function materialiseNamedInlineSource(
        string $source,
        string $filename,
        int $agentId,
        ?int $userId,
        ?PrincipalContext $context,
    ): MediaAsset {
        $id = $this->generateUuid();
        $principalId = $context !== null ? $context->principalId : 0;
        $ownerUserId = $context !== null ? ($context->ownerUserId ?? $userId) : $userId;

        $asset = new MediaAsset();
        $asset->id            = $id;
        $asset->user_id       = $ownerUserId;
        $asset->agent_id      = $agentId > 0 ? $agentId : null;
        $asset->principal_id  = $principalId > 0 ? (int) $principalId : null;
        $asset->plugin_slug   = 'spora-plugin-typst';
        $asset->tool_name     = 'typst.playground';
        $asset->mime_type     = 'text/x-typst';
        $asset->media_type    = MediaType::Document->value;
        $asset->byte_size     = strlen($source);
        $asset->filename      = $filename;
        $asset->storage_mode  = 'data_url';
        $asset->asset_token   = bin2hex(random_bytes(16));
        $asset->upload_source = 'tool';
        $asset->payload       = $source;
        $asset->asset_url     = MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.typ';
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
