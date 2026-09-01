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
 * CRUD on the principal's tier-2 example directory.
 *
 * Mirror of {@see TypstFontController}, scoped to `text/x-typst`
 * templates rather than font binaries. Examples are text-only, so
 * the `content` body field is always interpreted as UTF-8 source —
 * no base64 auto-detection (the controller for examples is simpler
 * because there's no binary ambiguity).
 */
final class TypstExampleController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
    ) {}

    /**
     * GET /api/v1/typst/examples
     *
     * Optional `?principal_id=N` lets the caller scope the listing
     * to any principal they can see (their own user-principal + the
     * group-principals they're a member of). Omitting the param
     * preserves the caller's own-principal default for backward
     * compatibility with the v1 clients.
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
        $store = $this->storeForPrincipal($principalId);

        return new JsonResponse([
            'data' => [
                'examples' => $store->list(TypstResourcePaths::KIND_EXAMPLE),
            ],
        ]);
    }

    /**
     * GET /api/v1/typst/examples/{name}
     */
    public function show(Request $request): Response
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
        $bytes = $store->read(TypstResourcePaths::KIND_EXAMPLE, $name);
        if ($bytes === null) {
            return $this->notFound('NOT_FOUND', sprintf('Example "%s" not found', $name));
        }
        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Length'      => (string) strlen($bytes),
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($name)),
        ]);
    }

    /**
     * POST /api/v1/typst/examples
     * body: { "name": "invoice.typ", "content": "= Invoice\n..." }
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

        try {
            $path = $store->write(TypstResourcePaths::KIND_EXAMPLE, $name, $content);
        } catch (RuntimeException $e) {
            return $this->unprocessable('VALIDATION_ERROR', $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                'example' => [
                    'name'   => $name,
                    'kind'   => TypstResourcePaths::KIND_EXAMPLE,
                    'size'   => strlen($content),
                    'path'   => $path,
                    'origin' => 'principal',
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/typst/examples/{name}
     */
    public function destroy(Request $request): JsonResponse
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
        try {
            $store->delete(TypstResourcePaths::KIND_EXAMPLE, $name);
        } catch (RuntimeException $e) {
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

    private function storeForPrincipal(int $principalId): TypstResourceStore
    {
        $paths = new TypstResourcePaths($this->paths(), $principalId);
        return new TypstResourceStore($paths);
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

    private function paths(): \Spora\Core\Paths
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return new \Spora\Core\Paths($basePath);
    }
}
