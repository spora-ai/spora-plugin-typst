<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstInvalidArgumentException;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
use Spora\Plugins\Typst\Services\TypstFilename;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD over the playground-side `text/x-typst` source rows.
 *
 * The compile endpoint ({@see TypstCompileController}) writes those
 * rows as a side-effect of compiling — `tool_name='typst.playground'`,
 * `mime_type='text/x-typst'`, `filename=<user-chosen>`. The source row
 * is what gives the `media_derivatives` join its natural key, so the
 * derivative (PDF / PNG / SVG) is keyed off the same parent id.
 *
 * This controller is the user-facing counterpart: list, open, save
 * edits, and delete. The "save edits" path (`update()`) is the one
 * the user clicks when they want to persist source changes without
 * re-rendering, and it also strips any existing derivatives so a
 * stale PDF/PNG/SVG doesn't sit next to a fresh source.
 *
 * Routes:
 *
 *   GET    /api/v1/typst/sources[?principal_id=N]  — list (filename + size + mtime)
 *   GET    /api/v1/typst/sources/{id}[?principal_id=N]      — fetch source bytes
 *   PUT    /api/v1/typst/sources/{id}[?principal_id=N]      — save edits (no compile)
 *   DELETE /api/v1/typst/sources/{id}[?principal_id=N]      — delete source + derivatives
 *
 * All routes scope to the caller's user-principal by default. The
 * `?principal_id=N` query param is honoured when set, but the
 * requested principal is intersected with the caller's visible
 * principals (user-principal + group-principals the user belongs
 * to) server-side so a user can never reach a principal they
 * can't act as. Out-of-scope ids surface as 404, not 403, so the
 * existence of someone else's file isn't leaked through the API.
 */
final class TypstPlaygroundSourceController
{
    use JsonControllerHelpers;

    private const TYPST_MIME_TYPE = 'text/x-typst';
    private const DEFAULT_FILENAME = 'playground.typ';

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
    ) {}

    /**
     * GET /api/v1/typst/sources[?principal_id=N]
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            return $this->unauthenticated();
        }

        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }

        $rows = MediaAsset::query()
            ->where('principal_id', $principalId)
            ->where('tool_name', 'typst.playground')
            ->where('mime_type', self::TYPST_MIME_TYPE)
            ->orderBy('updated_at', 'desc')
            ->select(['id', 'filename', 'byte_size', 'created_at', 'updated_at'])
            ->get();

        $sources = [];
        foreach ($rows as $a) {
            $sources[] = [
                'id'         => $a->id,
                'filename'   => $a->filename,
                'byte_size'  => (int) $a->byte_size,
                'created_at' => $a->created_at !== null ? $a->created_at->toIso8601String() : null,
                'updated_at' => $a->updated_at !== null ? $a->updated_at->toIso8601String() : null,
            ];
        }

        return new JsonResponse(['data' => ['sources' => $sources]]);
    }

    /**
     * POST /api/v1/typst/sources[?principal_id=N]
     *
     * Create a new playground source row WITHOUT compiling. This is
     * the operator-facing counterpart to `compile()`'s side-effect
     * materialisation: the playground UI's "Save" button calls this
     * when the user has typed source into an unsaved buffer (no
     * parent row yet) and wants to persist it without paying for a
     * render.
     *
     * Filename collisions no longer 409 — every call creates a
     * fresh row with a new UUID. The previous "second compile
     * overwrites the source row" behaviour is gone: the playground
     * UI's editor is opened by id (not filename), so duplicate
     * filenames are visible side-by-side in the picker rather than
     * silently stomping each other.
     *
     * Body: { "filename": "letter.typ", "content": "= Hello\n" }
     *
     * Returns 201 with the new row's id, filename, byte_size, and
     * updated_at. The frontend uses the id to wire subsequent
     * Save clicks to the update() path.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = $this->requireUserId();
            $inputs = $this->parseStoreInputs($request);
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (PlaygroundRequestFailed $e) {
            return $e->response;
        }

        $asset = $this->createSourceRow($userId, $principalId, $inputs['filename'], $inputs['content']);

        return new JsonResponse([
            'data' => [
                'id'         => $asset->id,
                'filename'   => $asset->filename,
                'byte_size'  => (int) $asset->byte_size,
                'created_at' => $asset->created_at->toIso8601String(),
                'updated_at' => $asset->updated_at->toIso8601String(),
            ],
        ], Response::HTTP_CREATED);
    }

    private function requireUserId(): int
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new PlaygroundRequestFailed($this->unauthenticated());
        }
        return (int) $userId;
    }

    /**
     * @return array{filename: string, content: string}
     */
    private function parseStoreInputs(Request $request): array
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            throw new PlaygroundRequestFailed($body);
        }
        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            throw new PlaygroundRequestFailed(
                $this->unprocessable('VALIDATION_ERROR', 'content is required and must be a string'),
            );
        }
        return [
            'filename' => $this->validateFilename($body['filename'] ?? null),
            'content'  => $content,
        ];
    }

    private function createSourceRow(int $userId, int $principalId, string $filename, string $content): MediaAsset
    {
        $id = self::generateUuid();
        $now = \Illuminate\Support\Carbon::now();
        $asset = new MediaAsset();
        $asset->id            = $id;
        $asset->user_id       = $userId;
        $asset->agent_id      = null;
        $asset->principal_id  = $principalId;
        $asset->plugin_slug   = 'spora-plugin-typst';
        $asset->tool_name     = 'typst.playground';
        $asset->mime_type     = self::TYPST_MIME_TYPE;
        $asset->media_type    = 'document';
        $asset->byte_size     = strlen($content);
        $asset->filename      = $filename;
        $asset->storage_mode  = 'data_url';
        $asset->asset_token   = bin2hex(random_bytes(16));
        $asset->upload_source = 'tool';
        $asset->payload       = $content;
        $asset->asset_url     = MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.typ';
        $asset->created_at    = $now;
        $asset->updated_at    = $now;
        $asset->save();
        return $asset;
    }

    /**
     * GET /api/v1/typst/sources/{id}[?principal_id=N]
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $userId = $this->requireUserId();
            $asset = $this->resolveOwnedSource($request, $userId);
        } catch (PlaygroundRequestFailed $e) {
            return $e->response;
        }

        $payload = (string) $asset->payload;

        return new JsonResponse([
            'data' => [
                'id'         => $asset->id,
                'filename'   => $asset->filename,
                'byte_size'  => (int) $asset->byte_size,
                'mime'       => $asset->mime_type,
                'content'    => $payload,
                'created_at' => $asset->created_at !== null ? $asset->created_at->toIso8601String() : null,
                'updated_at' => $asset->updated_at !== null ? $asset->updated_at->toIso8601String() : null,
            ],
        ]);
    }

    /**
     * PUT /api/v1/typst/sources/{id}[?principal_id=N]
     *
     * Persist source edits without re-rendering. Strips existing
     * derivatives so a stale render doesn't outlive its source.
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $userId = $this->requireUserId();
            $content = $this->parseUpdateContent($request);
            $asset = $this->resolveOwnedSource($request, $userId);
        } catch (PlaygroundRequestFailed $e) {
            return $e->response;
        }

        $asset->payload    = $content;
        $asset->byte_size  = strlen($content);
        $asset->updated_at = \Illuminate\Support\Carbon::now();
        $asset->save();

        // Stale derivatives point at this parent. Without a re-compile
        // they'll render the *old* source — deleting them keeps the
        // media archive honest. The next compile regenerates them.
        $this->deleteDerivativesFor($asset->id);

        return new JsonResponse([
            'data' => [
                'id'         => $asset->id,
                'filename'   => $asset->filename,
                'byte_size'  => (int) $asset->byte_size,
                'updated_at' => $asset->updated_at->toIso8601String(),
            ],
        ]);
    }

    private function parseUpdateContent(Request $request): string
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            throw new PlaygroundRequestFailed($body);
        }
        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            throw new PlaygroundRequestFailed(
                $this->unprocessable('VALIDATION_ERROR', 'content is required and must be a string'),
            );
        }
        return $content;
    }

    /**
     * DELETE /api/v1/typst/sources/{id}[?principal_id=N]
     *
     * Remove the source row and any derivatives linked to it. The
     * `media_assets` row + `media_derivatives` join + derivative
     * `media_assets` rows are all gone afterwards.
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $userId = $this->requireUserId();
            $asset = $this->resolveOwnedSource($request, $userId);
        } catch (PlaygroundRequestFailed $e) {
            return $e->response;
        }

        $this->deleteDerivativesFor($asset->id);
        $asset->delete();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Resolve the parent row by `id` and confirm the requested principal
     * owns it. Returns a 404 if the row is missing OR out of scope —
     * we don't want to leak the existence of another principal's files.
     *
     * The "requested principal" comes from `?principal_id=N` when
     * present, otherwise the caller's user-principal. The visible-
     * principals check in {@see resolvePrincipalId()} gates the
     * request so the user can't reach a principal they can't act as.
     */
    private function resolveOwnedSource(Request $request, int $userId): MediaAsset
    {
        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            throw new PlaygroundRequestFailed(
                $this->notFound('NOT_FOUND', $e->getMessage()),
            );
        }
        $id = (string) $request->attributes->get('id', '');
        if ($id === '') {
            throw new PlaygroundRequestFailed(
                $this->notFound('NOT_FOUND', 'source not found'),
            );
        }
        $asset = MediaAsset::query()
            ->where('id', $id)
            ->where('principal_id', $principalId)
            ->where('tool_name', 'typst.playground')
            ->where('mime_type', self::TYPST_MIME_TYPE)
            ->first();
        if ($asset === null) {
            throw new PlaygroundRequestFailed(
                $this->notFound('NOT_FOUND', 'source not found'),
            );
        }
        return $asset;
    }

    /**
     * Honour `?principal_id=N` when supplied; otherwise default to
     * the caller's user-principal. Out-of-scope ids surface as a
     * RuntimeException so the caller can map them to a 404.
     */
    private function resolvePrincipalId(Request $request, int $userId): int
    {
        $requested = $request->query->get('principal_id');
        if ($requested === null || $requested === '') {
            return (int) $this->principals->ensureUserPrincipal($userId)->id;
        }
        $requestedId = (int) $requested;
        if ($requestedId <= 0 || !in_array($requestedId, $this->principals->visiblePrincipalIdsFor($userId), true)) {
            throw new TypstRuntimeException('Principal not visible to caller');
        }
        return $requestedId;
    }

    /**
     * Validate the playground filename for the create / store path.
     * Delegates to {@see TypstFilename::sanitise()} so this controller,
     * {@see TypstCompileController::validateName()}, and the
     * `typst_compile` tool all share one definition of what counts
     * as a valid basename. Rejects path separators, control bytes,
     * and over-long names with a 422; auto-appends `.typ` when missing.
     *
     * Throws {@see PlaygroundRequestFailed} on rejection so the caller
     * stays within Sonar's S1142 budget.
     */
    private function validateFilename(mixed $raw): string
    {
        try {
            return TypstFilename::sanitise($raw, self::DEFAULT_FILENAME);
        } catch (TypstInvalidArgumentException $e) {
            throw new PlaygroundRequestFailed(
                $this->unprocessable('VALIDATION_ERROR', $e->getMessage()),
            );
        }
    }

    /**
     * UUIDv4 without the `ramsey/uuid` dependency. Identical to
     * {@see TypstCompileController::generateUuid()} so the playground
     * source rows use the same canonical id format as the inline
     * sources the compile endpoint materialises.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Drop every `media_derivatives` join pointing at this parent and
     * the derivative `media_assets` rows themselves. The actual asset
     * bytes are stored as `data_url` (inline payload) for the playground
     * scope, so no separate file-system cleanup is needed.
     */
    private function deleteDerivativesFor(string $parentId): void
    {
        $derivativeIds = Capsule::table('media_derivatives')
            ->where('parent_id', $parentId)
            ->pluck('derivative_id')
            ->all();
        if ($derivativeIds === []) {
            return;
        }
        Capsule::table('media_assets')
            ->whereIn('id', $derivativeIds)
            ->delete();
        Capsule::table('media_derivatives')
            ->where('parent_id', $parentId)
            ->delete();
    }
}

/**
 * Internal control-flow exception thrown by validation / lookup
 * helpers in {@see TypstPlaygroundSourceController} to unwind
 * request handling without piling up `return $errorResponse`
 * statements (which Sonar's S1142 counts). Carries the JsonResponse
 * the controller method would otherwise have returned inline.
 */
final class PlaygroundRequestFailed extends RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('playground request failed');
    }
}
