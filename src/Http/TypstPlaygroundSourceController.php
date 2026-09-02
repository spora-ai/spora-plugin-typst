<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\MediaAsset;
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
            ->where('mime_type', 'text/x-typst')
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
     * upsert: the playground UI's "Save" button calls this when the
     * user has typed source into an unsaved buffer (no parent row
     * yet) and wants to persist it without paying for a render. The
     * compile path can still upsert the row in place later — the
     * natural key `(principal_id, tool_name, filename)` is the same.
     *
     * Body: { "filename": "letter.typ", "content": "= Hello\n" }
     *
     * Returns 201 with the new row's id, filename, byte_size, and
     * updated_at. The frontend uses the id to wire subsequent
     * Save clicks to the update() path.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            return $this->unauthenticated();
        }

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            return $this->unprocessable('VALIDATION_ERROR', 'content is required and must be a string');
        }

        $filename = $this->validateFilename($body['filename'] ?? null);
        if ($filename instanceof JsonResponse) {
            return $filename;
        }

        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }

        // Reject if a row already exists for (principal, filename) —
        // the natural key is the same one the compile endpoint uses,
        // so we'd otherwise create a duplicate that compile() would
        // immediately overwrite. Force the operator to "Open" the
        // existing row or pick a fresh filename.
        $existing = MediaAsset::query()
            ->where('principal_id', $principalId)
            ->where('tool_name', 'typst.playground')
            ->where('filename', $filename)
            ->first();
        if ($existing !== null) {
            return $this->error(
                'FILENAME_TAKEN',
                sprintf('A playground source named "%s" already exists. Open it from the file picker to edit it.', $filename),
                Response::HTTP_CONFLICT,
            );
        }

        $id = self::generateUuid();
        $now = \Illuminate\Support\Carbon::now();
        $asset = new MediaAsset();
        $asset->id            = $id;
        $asset->user_id       = $userId;
        $asset->agent_id      = null;
        $asset->principal_id  = $principalId;
        $asset->plugin_slug   = 'spora-plugin-typst';
        $asset->tool_name     = 'typst.playground';
        $asset->mime_type     = 'text/x-typst';
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

    /**
     * GET /api/v1/typst/sources/{id}[?principal_id=N]
     */
    public function show(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            return $this->unauthenticated();
        }

        $asset = $this->resolveOwnedSource($request, $userId);
        if ($asset instanceof JsonResponse) {
            return $asset;
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
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            return $this->unauthenticated();
        }

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $content = $body['content'] ?? null;
        if (!is_string($content)) {
            return $this->unprocessable('VALIDATION_ERROR', 'content is required and must be a string');
        }

        $asset = $this->resolveOwnedSource($request, $userId);
        if ($asset instanceof JsonResponse) {
            return $asset;
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

    /**
     * DELETE /api/v1/typst/sources/{id}[?principal_id=N]
     *
     * Remove the source row and any derivatives linked to it. The
     * `media_assets` row + `media_derivatives` join + derivative
     * `media_assets` rows are all gone afterwards.
     */
    public function destroy(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            return $this->unauthenticated();
        }

        $asset = $this->resolveOwnedSource($request, $userId);
        if ($asset instanceof JsonResponse) {
            return $asset;
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
    private function resolveOwnedSource(Request $request, int $userId): MediaAsset|JsonResponse
    {
        try {
            $principalId = $this->resolvePrincipalId($request, $userId);
        } catch (RuntimeException $e) {
            return $this->notFound('NOT_FOUND', $e->getMessage());
        }

        $id = (string) $request->attributes->get('id', '');
        if ($id === '') {
            return $this->notFound('NOT_FOUND', 'source not found');
        }
        $asset = MediaAsset::query()
            ->where('id', $id)
            ->where('principal_id', $principalId)
            ->where('tool_name', 'typst.playground')
            ->where('mime_type', 'text/x-typst')
            ->first();
        if ($asset === null) {
            return $this->notFound('NOT_FOUND', 'source not found');
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
            throw new RuntimeException('Principal not visible to caller');
        }
        return $requestedId;
    }

    /**
     * Validate the playground filename for the create / store path.
     * Mirrors {@see TypstCompileController::validateName()} so the
     * two endpoints accept the same shape (a plain basename, or a
     * basename with `.typ` auto-appended). Rejects path separators,
     * control bytes, and over-long names with a 422. Returns the
     * validated name (with `.typ` appended when missing) on success.
     *
     * @return string|JsonResponse
     */
    private function validateFilename(mixed $raw)
    {
        if ($raw === null || $raw === '') {
            return 'playground.typ';
        }
        if (!is_string($raw)) {
            return $this->unprocessable('VALIDATION_ERROR', 'filename must be a string');
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return 'playground.typ';
        }
        if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $trimmed) === 1) {
            return $this->unprocessable(
                'VALIDATION_ERROR',
                'filename contains illegal characters (no path separators or control bytes)',
            );
        }
        if (strlen($trimmed) > 128) {
            return $this->unprocessable('VALIDATION_ERROR', 'filename is too long (max 128 chars)');
        }
        if (!str_ends_with($trimmed, '.typ')) {
            $trimmed .= '.typ';
        }
        return $trimmed;
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
