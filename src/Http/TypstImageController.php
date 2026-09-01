<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD on the principal's per-tenant image library.
 *
 * Mirror of {@see TypstFontController}, but each row is a real
 * `media_assets` row (so the asset_url is canonical — feed it to
 * ext-typst's `#image()` and the rendered PDF/PNG pulls the bytes via
 * core's `AssetController`).
 *
 * Why this is here and not in the Media Archive plugin: image uploads
 * for Typst renders are an operator concern, but the upload target is
 * the principal's *Typst* library, not the principal's media library.
 * Tagging the rows with `plugin_slug='spora-plugin-typst'` +
 * `tool_name='typst.image'` keeps them out of the Media Archive
 * library's LIST (which doesn't filter on plugin_slug today, but
 * future filter UIs will), so the two surfaces stay separable.
 */
final class TypstImageController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
        private readonly TypstImageStore $store,
    ) {}

    /**
     * GET /api/v1/typst/images
     */
    public function index(): JsonResponse
    {
        $principalId = $this->principalIdForCurrentUser();
        $rows = $this->store->listFor($principalId);

        return new JsonResponse([
            'data' => [
                'images' => array_map(
                    static fn(MediaAsset $asset): array => [
                        'id'         => $asset->id,
                        'filename'   => $asset->filename,
                        'mime_type'  => $asset->mime_type,
                        'byte_size'  => $asset->byte_size,
                        'asset_url'  => $asset->publicUrl(),
                        'created_at' => $asset->created_at?->toIso8601String(),
                    ],
                    $rows,
                ),
            ],
        ]);
    }

    /**
     * POST /api/v1/typst/images
     *
     * body: { "filename": "logo.png", "mime": "image/png", "content": "<base64 OR raw bytes>" }
     *
     * The `content` field accepts either base64-encoded bytes or a raw
     * UTF-8 string (handy for SVG, which is XML). Detection mirrors
     * {@see TypstFontController::decodeContent()}: valid base64 of
     * sufficient length wins; otherwise the string is treated as
     * raw bytes verbatim.
     */
    public function store(Request $request): JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $mime    = strtolower(trim((string) ($body['mime'] ?? '')));
        $content = $body['content'] ?? null;
        $name    = trim((string) ($body['filename'] ?? ''));

        if (!TypstImageStore::isAllowedMime($mime)) {
            return $this->unprocessable('UNSUPPORTED_MIME', sprintf(
                'Mime "%s" is not allowed (allowed: image/png, image/jpeg, image/webp, image/svg+xml)',
                $mime,
            ));
        }
        if (!is_string($content) || $content === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'content is required');
        }

        $bytes = $this->decodeContent($content);
        $userId = $this->auth->currentUserId();
        $principalId = $this->principalIdForCurrentUser();

        try {
            $asset = $this->store->create($bytes, $mime, [
                'principal_id' => $principalId,
                'user_id'      => $userId,
                'filename'     => $name !== '' ? $this->sanitizeFilename($name, $mime) : null,
            ]);
        } catch (RuntimeException $e) {
            return $this->unprocessable('VALIDATION_ERROR', $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                'image' => [
                    'id'         => $asset->id,
                    'filename'   => $asset->filename,
                    'mime_type'  => $asset->mime_type,
                    'byte_size'  => $asset->byte_size,
                    'asset_url'  => $asset->publicUrl(),
                    'created_at' => $asset->created_at?->toIso8601String(),
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/typst/images/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $id = (string) $request->attributes->get('id', '');
        $principalId = $this->principalIdForCurrentUser();
        try {
            $this->store->delete($id, $principalId);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }
        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    private function principalIdForCurrentUser(): int
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Authentication required');
        }
        return $this->principals->ensureUserPrincipal($userId)->id;
    }

    /**
     * Auto-detect base64 vs raw-text content. Same heuristic as the
     * font controller — kept inline here because the two controllers
     * have slightly different validation rules and a shared helper
     * would have to take 5+ parameters to be worth it.
     */
    private function decodeContent(string $content): string
    {
        $trimmed = trim($content);
        if (
            preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $trimmed) !== 0
            && strlen($trimmed) % 4 === 0
            && strlen($trimmed) >= 16
        ) {
            $decoded = base64_decode($trimmed, true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }
        return $content;
    }

    /**
     * Strip path-segments / shell metas from the user-supplied filename
     * before persisting it. The asset's stored filename is shown back
     * in the listing UI and embedded in the asset_url slug, so a
     * basename containing `/` would be a small but real surface area.
     */
    private function sanitizeFilename(string $name, string $mime): string
    {
        $base = basename(str_replace('\\', '/', $name));
        if ($base === '' || $base === '.' || $base === '..') {
            // basename() may return '.' on edge inputs — fall back to a
            // sensible default per mime.
            return 'typst-image.' . match ($mime) {
                'image/png'     => 'png',
                'image/jpeg'    => 'jpg',
                'image/webp'    => 'webp',
                'image/svg+xml' => 'svg',
                default         => 'bin',
            };
        }
        // Drop anything outside [A-Z a-z 0-9 . _ -] — keeps the asset
        // url slug web-safe and prevents the chat UI from breaking on
        // filenames with spaces / unicode.
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
    }
}
