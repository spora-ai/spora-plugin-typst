<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD on the principal's tier-2 font directory.
 *
 * Mirrors the {@see TypstResourceStore} contract: list returns the
 * union of skill-shipped + principal-uploaded fonts (deduplicated by
 * basename, tier-2 wins on collision), GET returns the bytes,
 * POST writes a new font, DELETE removes a principal font (the
 * skill-shipped ones can't be deleted — the store refuses them and
 * the controller surfaces the 422).
 *
 * The principal id is resolved from the auth session via
 * {@see PrincipalService::ensureUserPrincipal()}, mirroring how the
 * Media Archive plugin's controllers anchor writes to a principal.
 */
final class TypstFontController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
    ) {}

    /**
     * GET /api/v1/typst/fonts
     */
    public function index(): JsonResponse
    {
        $store = $this->storeForCurrentUser();

        return new JsonResponse([
            'data' => [
                'fonts' => $store->list(TypstResourcePaths::KIND_FONT),
            ],
        ]);
    }

    /**
     * GET /api/v1/typst/fonts/{name}
     */
    public function show(Request $request): Response
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
        $bytes = $store->read(TypstResourcePaths::KIND_FONT, $name);
        if ($bytes === null) {
            return $this->notFound('NOT_FOUND', sprintf('Font "%s" not found', $name));
        }
        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Length'      => (string) strlen($bytes),
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($name)),
        ]);
    }

    /**
     * POST /api/v1/typst/fonts
     * body: { "name": "Inter-Black.otf", "content": "<base64 OR raw text>" }
     */
    public function store(Request $request): JsonResponse
    {
        $store = $this->storeForCurrentUser();

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $name    = trim((string) ($body['name'] ?? ''));
        $content = $body['content'] ?? null;

        if ($name === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'name is required');
        }
        if (!is_string($content) || $content === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'content is required');
        }

        $bytes = $this->decodeContent($content);
        try {
            $path = $store->write(TypstResourcePaths::KIND_FONT, $name, $bytes);
        } catch (RuntimeException $e) {
            return $this->unprocessable('VALIDATION_ERROR', $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                'font' => [
                    'name'   => $name,
                    'kind'   => TypstResourcePaths::KIND_FONT,
                    'size'   => strlen($bytes),
                    'path'   => $path,
                    'origin' => 'principal',
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/typst/fonts/{name}
     */
    public function destroy(Request $request): JsonResponse
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
        try {
            $store->delete(TypstResourcePaths::KIND_FONT, $name);
        } catch (RuntimeException $e) {
            // Skill-shipped + missing-both map to 422 — the resource
            // exists logically (it's in the listing) but isn't
            // writable, which is a state issue not a missing-resource
            // one.
            return $this->unprocessable('NOT_DELETABLE', $e->getMessage());
        }
        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    private function storeForCurrentUser(): TypstResourceStore
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Authentication required');
        }
        $principalId = $this->principals->ensureUserPrincipal($userId)->id;
        $paths = new TypstResourcePaths($this->paths(), $principalId);
        return new TypstResourceStore($paths);
    }

    private function paths(): \Spora\Core\Paths
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return new \Spora\Core\Paths($basePath);
    }

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
