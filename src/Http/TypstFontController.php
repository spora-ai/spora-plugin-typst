<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
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
     *
     * Optional `?principal_id=N` lets the caller scope the listing
     * to any principal they can see (their own user-principal + the
     * group-principals they're a member of). Omitting the param
     * preserves the caller's own-principal default for backward
     * compatibility with the v1 clients.
     *
     * Skill-shipped fonts (tier-1, plugin-bundled Inter OFL) are
     * always included regardless of the requested principal — the
     * `TypstResourceStore::list()` union already mixes tier-1 and
     * tier-2 (deduplicated, tier-2 wins on basename collision).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new TypstRuntimeException('Authentication required');
        }
        // Materialise the user-principal first so the chip-row's
        // ?principal_id is in visiblePrincipalIdsFor() on the very
        // first GET in a session (when no principal-tier row exists
        // yet for this user, the visibility list is []).
        $this->principals->ensureUserPrincipal($userId);
        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }
        $store = $this->storeForPrincipal($principalId);

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
        try {
            $store = $this->storeForRequest($request);
            $name = (string) $request->attributes->get('name', '');
        } catch (FontPrincipalNotVisible $e) {
            return $e->response;
        }
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
        try {
            $store = $this->storeForRequest($request);
            $inputs = $this->parseStoreInputs($request);
            $bytes = $this->decodeContent($inputs['content']);
            $path = $store->write(TypstResourcePaths::KIND_FONT, $inputs['name'], $bytes);
        } catch (FontPrincipalNotVisible | FontValidationFailed $e) {
            return $e->response;
        } catch (RuntimeException $e) {
            return $this->unprocessable('VALIDATION_ERROR', $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                'font' => [
                    'name'   => $inputs['name'],
                    'kind'   => TypstResourcePaths::KIND_FONT,
                    'size'   => strlen($bytes),
                    'path'   => $path,
                    'origin' => 'principal',
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * @return array{name: string, content: string}
     */
    private function parseStoreInputs(Request $request): array
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            throw new FontValidationFailed($body);
        }
        $name    = trim((string) ($body['name'] ?? ''));
        $content = $body['content'] ?? null;

        if ($name === '') {
            throw new FontValidationFailed(
                $this->unprocessable('VALIDATION_ERROR', 'name is required'),
            );
        }
        if (!is_string($content) || $content === '') {
            throw new FontValidationFailed(
                $this->unprocessable('VALIDATION_ERROR', 'content is required'),
            );
        }

        return ['name' => $name, 'content' => $content];
    }

    /**
     * DELETE /api/v1/typst/fonts/{name}
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $store = $this->storeForRequest($request);
            $name = (string) $request->attributes->get('name', '');
            $store->delete(TypstResourcePaths::KIND_FONT, $name);
        } catch (FontPrincipalNotVisible $e) {
            return $e->response;
        } catch (RuntimeException $e) {
            // Skill-shipped + missing-both map to 422 — the resource
            // exists logically (it's in the listing) but isn't
            // writable, which is a state issue not a missing-resource
            // one.
            return $this->unprocessable('NOT_DELETABLE', $e->getMessage());
        }
        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Same scope logic as {@see index()} — materialise the
     * user-principal first, resolve ?principal_id=N via the
     * visibility check, throw a sentinel for invisible principals.
     *
     * @throws FontPrincipalNotVisible when the request names a
     *         principal the caller can't see. Caught by the public
     *         endpoints and surfaced as 404.
     */
    private function storeForRequest(Request $request): TypstResourceStore
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new TypstRuntimeException('Authentication required');
        }
        $this->principals->ensureUserPrincipal($userId);
        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            throw new FontPrincipalNotVisible($this->notFound('NOT_FOUND', $e->getMessage()));
        }
        return $this->storeForPrincipal($principalId);
    }

    /**
     * Build a store scoped to a specific principal id (used by the
     * GET endpoint when `?principal_id=N` is supplied). The principal
     * id is treated as a public id — the caller has already passed
     * the visibility check in {@see resolvePrincipalId()}.
     */
    private function storeForPrincipal(int $principalId): TypstResourceStore
    {
        $paths = new TypstResourcePaths($this->paths(), $principalId);
        return new TypstResourceStore($paths);
    }

    /**
     * Resolve the principal id from `?principal_id=N`, falling back to
     * the caller's own user-principal. Validates the requested id is
     * in `visiblePrincipalIds` for the caller — throws a sentinel
     * `RuntimeException` if not, so a probe can't enumerate principals
     * the operator can't see. The controller catches the sentinel and
     * surfaces a 404 envelope (matches the existing pattern in
     * `destroy()` which catches `RuntimeException` from the store).
     */
    private function resolvePrincipalId(Request $request, int $userId): int
    {
        $requested = $request->query->get('principal_id');
        if ($requested === null || $requested === '') {
            return $this->principals->ensureUserPrincipal($userId)->id;
        }
        $requestedId = (int) $requested;
        if ($requestedId <= 0 || !in_array($requestedId, $this->principals->visiblePrincipalIdsFor($userId), true)) {
            throw new TypstRuntimeException('Principal not visible to caller');
        }
        return $requestedId;
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

/**
 * Internal control-flow exception thrown by
 * {@see TypstFontController::parseStoreInputs()} to unwind request
 * parsing without piling up `return $errorResponse` statements
 * (SonarCloud's S1142 budget). Carries the JsonResponse the public
 * method would otherwise have returned inline.
 */
final class FontValidationFailed extends RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('font validation failed');
    }
}

/**
 * Sentinel for invisible-principal requests; the public endpoints'
 * single catch arm unwinds with `$e->response` instead of a second
 * inline `return $this->notFound(...)` (Sonar's S1142 budget).
 */
final class FontPrincipalNotVisible extends RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('font: principal not visible to caller');
    }
}
