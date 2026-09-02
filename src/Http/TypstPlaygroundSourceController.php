<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\MediaAsset;
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
 *   GET    /api/v1/typst/sources            — list (filename + size + mtime)
 *   GET    /api/v1/typst/sources/{id}       — fetch source bytes
 *   PUT    /api/v1/typst/sources/{id}       — save edits (no compile)
 *   DELETE /api/v1/typst/sources/{id}       — delete source + derivatives
 *
 * All routes scope to the caller's user-principal so a user can't
 * list / read / write / delete another user's files. Out-of-scope
 * ids surface as 404, not 403, so the existence of someone else's
 * file isn't leaked through the API.
 */
final class TypstPlaygroundSourceController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
    ) {}

    /**
     * GET /api/v1/typst/sources
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            return $this->unauthenticated();
        }

        $principal = $this->principals->ensureUserPrincipal($userId);
        $rows = MediaAsset::query()
            ->where('principal_id', (int) $principal->id)
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
     * GET /api/v1/typst/sources/{id}
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
     * PUT /api/v1/typst/sources/{id}
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
     * DELETE /api/v1/typst/sources/{id}
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
     * Resolve the parent row by `id` and confirm the caller's principal
     * owns it. Returns a 404 if the row is missing OR out of scope —
     * we don't want to leak the existence of another user's files.
     */
    private function resolveOwnedSource(Request $request, int $userId): MediaAsset|JsonResponse
    {
        $id = (string) $request->attributes->get('id', '');
        if ($id === '') {
            return $this->notFound('NOT_FOUND', 'source not found');
        }
        $principal = $this->principals->ensureUserPrincipal($userId);
        $asset = MediaAsset::query()
            ->where('id', $id)
            ->where('principal_id', (int) $principal->id)
            ->where('tool_name', 'typst.playground')
            ->where('mime_type', 'text/x-typst')
            ->first();
        if ($asset === null) {
            return $this->notFound('NOT_FOUND', 'source not found');
        }
        return $asset;
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
