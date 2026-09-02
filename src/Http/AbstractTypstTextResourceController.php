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
        protected readonly TypstResourcePaths $paths,
        protected readonly TypstResourceStore $store,
    ) {}

    /**
     * Subclass returns the kind constant for the URL prefix they serve.
     */
    abstract protected function kind(): string;

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
                $this->pluralName() => $store->list($this->kind()),
            ],
        ]);
    }

    public function show(Request $request): Response
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
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
            $path = $store->write($this->kind(), $name, $content);
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
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request): JsonResponse
    {
        $store = $this->storeForCurrentUser();
        $name = (string) $request->attributes->get('name', '');
        try {
            $store->delete($this->kind(), $name);
        } catch (RuntimeException $e) {
            return $this->unprocessable('NOT_DELETABLE', $e->getMessage());
        }
        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    protected function storeForCurrentUser(): TypstResourceStore
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new RuntimeException('Authentication required');
        }
        $principalId = $this->principals->ensureUserPrincipal($userId)->id;
        $paths = new TypstResourcePaths($this->paths(), $principalId);
        return new TypstResourceStore($paths);
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
            throw new RuntimeException('Principal not visible to caller');
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
