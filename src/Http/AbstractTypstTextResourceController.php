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
 * Shared CRUD for `.typ` text resources: `template` (document skeletons)
 * and `example` (pattern snippets the LLM cribs from).
 *
 * The two kinds share the same wire shape (UTF-8 source bytes, no
 * base64 step), so they're served by two thin subclasses that differ
 * only in the {@see TypstResourcePaths::KIND_*} constant + URL prefix
 * — see {@see TypstTemplateController} / {@see TypstExampleController}.
 *
 * Mirror of {@see TypstFontController}, with the base64 step dropped
 * because `.typ` files are always UTF-8 plaintext.
 */
abstract class AbstractTypstTextResourceController
{
    use JsonControllerHelpers;

    public function __construct(
        protected readonly AuthService $auth,
        protected readonly PrincipalService $principals,
    ) {}

    /**
     * Subclass returns the kind constant for the URL prefix they serve.
     */
    abstract protected function kind(): string;

    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new TypstRuntimeException('Authentication required');
        }
        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }
        $store = $this->storeForPrincipal($principalId);

        return new JsonResponse([
            'data' => [
                $this->pluralName() => $store->list($this->kind()),
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        try {
            $store = $this->storeForRequest($request);
            $name = (string) $request->attributes->get('name', '');
        } catch (ResourcePrincipalNotVisible $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }
        $bytes = $store->read($this->kind(), $name);
        if ($bytes === null) {
            return $this->notFound('NOT_FOUND', sprintf('%s "%s" not found', ucfirst($this->singularName()), $name));
        }
        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Length'      => (string) strlen($bytes),
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($name)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $store = $this->storeForRequest($request);
            $inputs = $this->parseStoreInputs($request);
            $path = $store->write($this->kind(), $inputs['name'], $inputs['content']);
        } catch (ResourcePrincipalNotVisible $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        } catch (ResourceValidationFailed $e) {
            return $e->response;
        } catch (RuntimeException $e) {
            return $this->unprocessable('VALIDATION_ERROR', $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                $this->singularName() => [
                    'name'   => $inputs['name'],
                    'kind'   => $this->kind(),
                    'size'   => strlen($inputs['content']),
                    'path'   => $path,
                    'origin' => 'principal',
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Replace (or create) a principal-tier resource by basename.
     * Body shape: `{ "content": "..." }` — the basename comes from
     * the URL so a typo in the body can't silently rename the row.
     *
     * `TypstResourceStore::write()` is overwrite-by-default, so this
     * is the same code path as `store()` minus the name field:
     *   - 422 if the basename fails the regex / length validator
     *     (delegated to the store).
     *   - 200 + overwrite when a tier-2 (principal) row already
     *     exists with that basename.
     *   - 200 + tier-2 shadow when only a tier-1 (skill-shipped)
     *     row exists with that basename — the shadow wins on
     *     subsequent reads. This is intentional and tested:
     *     "PUT /typst/templates/{name} allows shadowing a
     *     skill-shipped template".
     *
     * Skill-shipped rows are NOT gated against mutation — operators
     * can shadow them. The only resource-level gate that lives in
     * the store is on `delete()`, which throws when the basename
     * doesn't exist in tier-2 (skill-only basenames can't be
     * deleted because there's nothing to remove).
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $name = (string) $request->attributes->get('name', '');
            if ($name === '') {
                throw new ResourceValidationFailed(
                    $this->unprocessable('VALIDATION_ERROR', 'name is required in the URL'),
                );
            }
            $store = $this->storeForRequest($request);
            $content = $this->parseUpdateContent($request);
            $path = $store->write($this->kind(), $name, $content);
        } catch (ResourcePrincipalNotVisible $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        } catch (ResourceValidationFailed $e) {
            return $e->response;
        } catch (RuntimeException $e) {
            return $this->unprocessable('VALIDATION_ERROR', $e->getMessage());
        }

        return new JsonResponse([
            'data' => [
                $this->singularName() => [
                    'name'   => $name,
                    'kind'   => $this->kind(),
                    'size'   => strlen($content),
                    'path'   => $path,
                    'origin' => 'principal',
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * @return array{name: string, content: string}
     */
    private function parseStoreInputs(Request $request): array
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            throw new ResourceValidationFailed($body);
        }
        $name    = trim((string) ($body['name'] ?? ''));
        $content = $body['content'] ?? null;

        if ($name === '') {
            throw new ResourceValidationFailed(
                $this->unprocessable('VALIDATION_ERROR', 'name is required'),
            );
        }
        if (!is_string($content) || $content === '') {
            throw new ResourceValidationFailed(
                $this->unprocessable('VALIDATION_ERROR', 'content is required'),
            );
        }

        return ['name' => $name, 'content' => $content];
    }

    /**
     * Extract `content` from a PUT body. Mirrors
     * {@see parseStoreInputs()} minus the name field — the URL is
     * the source of truth for which resource is being replaced.
     */
    private function parseUpdateContent(Request $request): string
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            throw new ResourceValidationFailed($body);
        }
        $content = $body['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new ResourceValidationFailed(
                $this->unprocessable('VALIDATION_ERROR', 'content is required'),
            );
        }
        return $content;
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $store = $this->storeForRequest($request);
            $name = (string) $request->attributes->get('name', '');
            $store->delete($this->kind(), $name);
        } catch (ResourcePrincipalNotVisible $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->unprocessable('NOT_DELETABLE', $e->getMessage());
        }
        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Resolve a store scoped to the principal named by `?principal_id=N`,
     * falling back to the caller's own user-principal when the param
     * is absent. Mirrors {@see index()} so list/show/store/update/destroy
     * all read/write the same principal — fixing the bug where uploads
     * in a non-default principal "vanished after reload" because the
     * store/update paths were pinned to the user's own principal while
     * the listing path honored `?principal_id`.
     *
     * @throws ResourcePrincipalNotVisible when the request names a
     *         principal the caller can't see. Caught by the public
     *         endpoints and surfaced as 404 — matches the existing
     *         `index()` pattern.
     */
    protected function storeForRequest(Request $request): TypstResourceStore
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new TypstRuntimeException('Authentication required');
        }
        // Materialise the caller's user-principal so visibility
        // checks have a row to anchor on.
        $this->principals->ensureUserPrincipal($userId);
        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            throw new ResourcePrincipalNotVisible($e->getMessage(), previous: $e);
        }
        return $this->storeForPrincipal($principalId);
    }

    protected function storeForPrincipal(int $principalId): TypstResourceStore
    {
        $paths = new TypstResourcePaths($this->paths(), $principalId);
        return new TypstResourceStore($paths);
    }

    protected function resolvePrincipalId(Request $request, int $userId): int
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

    protected function paths(): \Spora\Core\Paths
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return new \Spora\Core\Paths($basePath);
    }

    /**
     * Wire key for the listing envelope. Mirrors the URL segment so
     * the JSON response reads `{"templates": [...]}` /
     * `{"examples": [...]}`.
     */
    abstract protected function pluralName(): string;

    /**
     * Singular wire key for the upload/show response envelope.
     */
    abstract protected function singularName(): string;
}

/**
 * Internal control-flow exception thrown by
 * {@see AbstractTypstTextResourceController::parseStoreInputs()} to
 * unwind request parsing without piling up `return $errorResponse`
 * statements (which Sonar's S1142 counts). Carries the JsonResponse
 * the public method would otherwise have returned inline.
 */
final class ResourceValidationFailed extends RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('resource validation failed');
    }
}

/**
 * Sentinel thrown by {@see AbstractTypstTextResourceController::storeForRequest()}
 * when the request names a principal the caller can't see. Surfaced
 * as 404 by the public endpoints so a probe can't enumerate other
 * principals via this route.
 */
final class ResourcePrincipalNotVisible extends RuntimeException {}
