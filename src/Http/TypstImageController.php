<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD on the principal's filesystem image library.
 *
 * Replaces the earlier media_assets-backed image store. The plugin's
 * image uploads now live as plain files at
 * `<storage>/typst/images/<principal>/<basename>`, served by
 * {@see show()} at `/api/v1/typst/images/{basename}` so the
 * `#image("…")` syntax in Typst source resolves to a stable URL
 * (independent of any media-archive row's UUID).
 *
 * Operators are expected to upload images via the dedicated `Images`
 * tab in the admin UI. The Playground result panel shows the rendered
 * PDF/PNG/SVG (a media derivative, not an image-library entry) and
 * pastes its `/api/v1/assets/<uuid>.<ext>` URL into the source when
 * the user clicks "Copy URL".
 */
final class TypstImageController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
        private readonly Paths $paths,
    ) {}

    /**
     * GET /api/v1/typst/images
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Authentication required');
        }
        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }
        $rows = $this->storeForPrincipal($principalId)->list();
        $buildUrl = fn(string $name): string => $this->publicUrlFor($principalId, $name);

        return new JsonResponse([
            'data' => [
                'images' => array_map(
                    static fn(array $row): array => [
                        'name'        => $row['name'],
                        'mime'        => $row['mime'],
                        'size'        => $row['size'],
                        'modified_at' => $row['modified_at'],
                        'url'         => $buildUrl($row['name']),
                    ],
                    $rows,
                ),
            ],
        ]);
    }

    /**
     * GET /api/v1/typst/images/{name}
     *
     * Streams the raw image bytes with the right Content-Type so the
     * browser and the chat UI's `<img src>` can both consume it
     * directly. `<iframe>`-able MIMEs are also returned with
     * `inline` Content-Disposition to keep preview thumbnails tidy.
     */
    public function show(Request $request): Response
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
        $bytes = $store->read($name);
        if ($bytes === null) {
            return $this->notFound('NOT_FOUND', sprintf('Image "%s" not found', $name));
        }
        $mime = $this->mimeFromName($name);
        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type'        => $mime,
            'Content-Length'      => (string) strlen($bytes),
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($name)),
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }

    /**
     * POST /api/v1/typst/images
     * body: { "filename": "logo.png", "mime": "image/png", "content": "<base64 OR raw UTF-8 SVG>" }
     *
     * `content` accepts base64 (binary uploads) or raw UTF-8 (SVG).
     * Detection matches the earlier controller's heuristic — valid
     * base64 of sufficient length wins; otherwise the string is
     * treated as raw bytes verbatim.
     */
    public function store(Request $request): JsonResponse
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $mime    = strtolower(trim((string) ($body['mime'] ?? '')));
        $content = $body['content'] ?? null;
        $name    = $body['filename'] ?? null;

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
        try {
            $row = $this->storeForCurrentUser()->write($bytes, $mime, is_string($name) ? $name : null);
        } catch (RuntimeException $e) {
            return $this->unprocessable('VALIDATION_ERROR', $e->getMessage());
        }

        $principalId = $this->principalIdForCurrentUser();
        return new JsonResponse([
            'data' => [
                'image' => [
                    'name'        => $row['name'],
                    'mime'        => $row['mime'],
                    'size'        => $row['size'],
                    'modified_at' => $row['modified_at'],
                    'url'         => $this->publicUrlFor($principalId, $row['name']),
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/typst/images/{name}
     */
    public function destroy(Request $request): JsonResponse
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
        try {
            $store->delete($name);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }
        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    private function storeForCurrentUser(): TypstImageStore
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Authentication required');
        }
        return $this->storeForPrincipal($this->principalIdForCurrentUser());
    }

    private function storeForPrincipal(int $principalId): TypstImageStore
    {
        $paths = new TypstResourcePaths($this->paths, $principalId);
        return new TypstImageStore($paths);
    }

    private function principalIdForCurrentUser(): int
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Authentication required');
        }
        return $this->principals->ensureUserPrincipal($userId)->id;
    }

    private function resolvePrincipalId(Request $request, int $userId): int
    {
        $requested = $request->query->get('principal_id');
        if ($requested === null || $requested === '') {
            return $this->principals->ensureUserPrincipal($userId)->id;
        }
        $requestedId = (int) $requested;
        if ($requestedId <= 0 || !in_array($requestedId, $this->principals->visiblePrincipalIdsFor($userId), true)) {
            throw new RuntimeException('Principal not visible to caller');
        }
        return $requestedId;
    }

    private function publicUrlFor(int $principalId, string $name): string
    {
        return '/api/v1/typst/images/' . rawurlencode($name);
    }

    private function mimeFromName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return TypstImageStore::EXT_TO_MIME[$ext] ?? 'application/octet-stream';
    }

    /**
     * Auto-detect base64 vs raw-text content. Valid base64 of
     * sufficient length wins; otherwise the string is treated as
     * raw bytes verbatim (handy for SVG, which is XML).
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
}
