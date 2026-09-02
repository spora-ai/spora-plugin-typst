<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use InvalidArgumentException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Playground-side counterpart to {@see \Spora\Plugins\Typst\Tools\TypstRenderTool}.
 *
 * The tool exists so an LLM can ask Spora to compile Typst source; this
 * controller exists so the operator UI's Playground tab can do the same
 * against a click. Both paths flow through {@see MediaDerivativeService::create()}
 * so the resulting PDF/PNG/SVG lands in the caller's media-assets pool
 * and surfaces in chat via `/api/v1/assets/<uuid>.<ext>`.
 *
 * Body:
 *
 *   {
 *     "source": "= Hello, Typst!\n",
 *     "name":   "letter.typ",                // default "playground.typ"
 *     "format": "pdf" | "png" | "svg",       // default pdf
 *     "page":   0,                            // png only (0-indexed)
 *     "dpi":    144                           // png only (36..600)
 *   }
 *
 * The controller materialises an inline `text/x-typst` parent row so
 * the natural-key on `media_derivatives` is well-defined (the producer
 * operates on a {@see MediaAsset}, not raw bytes) and re-renders are
 * idempotent: hitting the endpoint twice with the same source overwrites
 * the existing derivative's bytes rather than stacking rows.
 *
 * The source-side natural key is
 * `(principal_id, tool_name='typst.playground', filename)`. The operator
 * picks the filename (default `playground.typ` for the legacy "untitled"
 * flow), and a second compile with the same filename overwrites the
 * existing parent row in place rather than stacking rows in the media
 * archive. List / open / edit / delete of those parent rows is handled
 * by {@see TypstPlaygroundSourceController}; this controller's only
 * job is to keep the parent row current as a side-effect of compiling.
 *
 * For PDF the controller also produces a first-page PNG sibling so the
 * UI can render an inline preview without a second round-trip — the
 * chat UI's `MediaEmbed` does the same dance for tool responses.
 */
final class TypstCompileController
{
    use JsonControllerHelpers;

    private const DEFAULT_NAME = 'playground.typ';

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
        private readonly MediaDerivativeService $derivativeService,
        private readonly TypstWorldFactory $worldFactory,
    ) {}

    /**
     * POST /api/v1/typst/compile
     */
    public function compile(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            return $this->unauthenticated();
        }

        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $source = $body['source'] ?? null;
        if (!is_string($source) || trim($source) === '') {
            return $this->unprocessable('VALIDATION_ERROR', 'source is required and must be a non-empty string');
        }

        $name = $this->validateName($body['name'] ?? null);
        if ($name instanceof JsonResponse) {
            return $name;
        }

        $format = strtolower(trim((string) ($body['format'] ?? 'pdf')));
        if (!in_array($format, ['pdf', 'png', 'svg'], true)) {
            return $this->unprocessable('VALIDATION_ERROR', sprintf(
                'invalid format "%s" (expected: pdf, png, svg)',
                $format,
            ));
        }

        $page = isset($body['page']) ? max(0, (int) $body['page']) : null;
        $dpi  = isset($body['dpi']) ? max(36.0, min(600.0, (float) $body['dpi'])) : null;

        $producer = $this->findProducer();
        if ($producer === null) {
            return $this->error(
                'PRODUCER_UNAVAILABLE',
                'TypstRenderProducer is not registered. Was the plugin boot hooked correctly?',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $context = $this->resolveContext($userId);

        try {
            $parent = $this->upsertInlineSource($source, $name, $context);
        } catch (Throwable $e) {
            return $this->unprocessable('VALIDATION_ERROR', 'failed to persist inline source: ' . $e->getMessage());
        }

        try {
            $output = $producer->produce(
                source: $parent,
                format: $format,
                options: array_filter([
                    'page' => $page,
                    'dpi'  => $dpi,
                ], static fn($v): bool => $v !== null),
            );
        } catch (TypstCompilationException $e) {
            $diagnostics = [];
            foreach ($e->diagnostics as $diag) {
                $diagnostics[] = ['message' => self::sanitiseDiagnostic($diag->message())];
            }
            if ($diagnostics === []) {
                $diagnostics[] = ['message' => self::sanitiseDiagnostic($e->getMessage())];
            }
            return new JsonResponse(
                ['error' => ['code' => 'COMPILATION_FAILED', 'message' => 'Typst compilation failed', 'diagnostics' => $diagnostics]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->unprocessable('COMPILATION_FAILED', self::sanitiseDiagnostic($e->getMessage()));
        } catch (Throwable $e) {
            return $this->error(
                'COMPILATION_FAILED',
                'typst compile: ' . self::sanitiseDiagnostic($e->getMessage()),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $derivative = $this->derivativeService->create(
                parent: $parent,
                output: $output,
                format: $format,
                producerPlugin: $producer->pluginSlug(),
                producerOperation: $producer->operationName(),
                userId: $userId,
                context: $context,
            );
        } catch (Throwable $e) {
            return $this->error(
                'PERSISTENCE_FAILED',
                'failed to persist derivative: ' . $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $payload = [
            'derivative_id' => $derivative->id,
            'asset_url'     => $derivative->publicUrl(),
            'source_id'     => $parent->id,
            'source_name'   => $parent->filename,
            'format'        => $format,
            'mime'          => $derivative->mime_type,
            'size'          => $derivative->byte_size,
            'width'         => $derivative->width,
            'height'        => $derivative->height,
        ];

        if ($format === 'pdf') {
            $previewUrl = $this->firstPagePngUrl($parent);
            if ($previewUrl !== '') {
                $payload['preview_url'] = $previewUrl;
            }
        }

        return new JsonResponse(['data' => $payload], Response::HTTP_OK);
    }

    private function findProducer(): ?TypstRenderProducer
    {
        foreach (MediaDerivativeProducerDiscovery::all() as $class) {
            if ($class === TypstRenderProducer::class) {
                return new $class($this->worldFactory);
            }
        }
        return null;
    }

    /**
     * Mirrors {@see MediaDerivativeController::resolveContext()}: the
     * principal is the caller's user-principal; both owner and runner
     * id are the caller. Uses {@see PrincipalService::ensureUserPrincipal()}
     * to materialise the row on demand (idempotent — same id every call).
     */
    private function resolveContext(int $userId): PrincipalContext
    {
        $principal = $this->principals->ensureUserPrincipal($userId);
        return new PrincipalContext(
            principalId: (int) $principal->id,
            type: (string) $principal->type,
            ownerUserId: $userId,
            runnerUserId: $userId,
        );
    }

    /**
     * Resolve the playground filename. Accepts null/empty (→ default),
     * a plain basename, or a basename with the `.typ` suffix added if
     * missing. Rejects anything that would escape the principal's
     * directory (path traversal, control chars, NUL bytes).
     *
     * @return string|JsonResponse The validated name, or a 422 response
     *                             the caller should short-circuit with.
     */
    private function validateName(mixed $raw): string|JsonResponse
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_NAME;
        }
        if (!is_string($raw)) {
            return $this->unprocessable('VALIDATION_ERROR', 'name must be a string');
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return self::DEFAULT_NAME;
        }
        if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $trimmed) === 1) {
            return $this->unprocessable(
                'VALIDATION_ERROR',
                'name contains illegal characters (no path separators or control bytes)',
            );
        }
        if (strlen($trimmed) > 128) {
            return $this->unprocessable('VALIDATION_ERROR', 'name is too long (max 128 chars)');
        }
        if (!str_ends_with($trimmed, '.typ')) {
            $trimmed .= '.typ';
        }
        return $trimmed;
    }

    /**
     * Look up an existing playground source by its natural key
     * `(principal_id, tool_name='typst.playground', filename)` and
     * overwrite its `payload` in place. If no row exists, create a
     * fresh one. The derivative natural key
     * `(parent_id, format, producer_plugin, producer_operation)` then
     * keys off the same id, so the next compile of the same file
     * overwrites both source and render in place.
     */
    private function upsertInlineSource(string $source, string $name, PrincipalContext $context): MediaAsset
    {
        $principalId = $context->principalId > 0 ? (int) $context->principalId : null;
        $existing = MediaAsset::query()
            ->where('principal_id', $principalId)
            ->where('tool_name', 'typst.playground')
            ->where('filename', $name)
            ->first();

        if ($existing !== null) {
            $existing->payload     = $source;
            $existing->byte_size   = strlen($source);
            $existing->updated_at  = \Illuminate\Support\Carbon::now();
            $existing->save();
            return $existing;
        }

        $id = self::generateUuid();
        $asset = new MediaAsset();
        $asset->id            = $id;
        $asset->user_id       = $context->ownerUserId;
        $asset->agent_id      = null;
        $asset->principal_id  = $principalId;
        $asset->plugin_slug   = 'spora-plugin-typst';
        $asset->tool_name     = 'typst.playground';
        $asset->mime_type     = 'text/x-typst';
        $asset->media_type    = MediaType::Document->value;
        $asset->byte_size     = strlen($source);
        $asset->filename      = $name;
        $asset->storage_mode  = 'data_url';
        $asset->asset_token   = bin2hex(random_bytes(16));
        $asset->upload_source = 'tool';
        $asset->payload       = $source;
        $asset->asset_url     = MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.typ';
        $asset->created_at    = \Illuminate\Support\Carbon::now();
        $asset->updated_at    = $asset->created_at;
        $asset->save();

        return $asset;
    }

    /**
     * When the requested format is PDF, render the first page as PNG so
     * the playground can render an inline preview. Mirrors the chat UI's
     * `MediaEmbed` path. The PNG lands as a sibling derivative under the
     * same parent row.
     */
    private function firstPagePngUrl(MediaAsset $parent): string
    {
        $producer = $this->findProducer();
        if ($producer === null) {
            return '';
        }
        try {
            $png = $producer->produce($parent, 'png', ['page' => 0, 'dpi' => 144.0]);
        } catch (Throwable) {
            return '';
        }
        try {
            $pngDerivative = $this->derivativeService->create(
                parent: $parent,
                output: $png,
                format: 'png',
                producerPlugin: $producer->pluginSlug(),
                producerOperation: $producer->operationName(),
                userId: null,
                context: null,
            );
        } catch (Throwable) {
            return '';
        }
        return $pngDerivative->publicUrl();
    }

    /**
     * UUIDv4 without the `ramsey/uuid` dependency. Identical to
     * {@see MediaArchiveIngestPipeline::generateUuid()} so derivative
     * ids share the same canonical format.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Strip the local filesystem paths that ext-typst embeds in its
     * diagnostic messages ("file not found (searched at
     * /Users/fabeat/Development/Spora/.../skills/typst/templates/foo.jpg)").
     *
     * The raw message is great for an LLM that needs to debug a
     * build, but it's a footgun in a public-facing error surface:
     * it leaks the operator's filesystem layout, the project's
     * vendor / plugin paths, and sometimes the principal's home
     * directory. Two passes:
     *
     *   1. replace any `(searched at <path>)` clause with
     *      `(file not found)` — the diagnostic still tells the
     *      user WHAT happened without telling them WHERE we looked.
     *   2. strip any remaining absolute filesystem path
     *      (`/Users/...`, `/home/...`, `C:\...`) and replace with
     *      `<path>` — a final safety net for any future diagnostic
     *      shape that we don't catch in (1).
     */
    private static function sanitiseDiagnostic(string $message): string
    {
        $message = preg_replace(
            '/\(searched at [^)]+\)/',
            '(file not found)',
            $message,
        ) ?? $message;
        // POSIX absolute paths (incl. /Users/..., /home/..., /opt/...,
        // /var/..., /tmp/..., /private/... on macOS).
        $message = preg_replace(
            '#(/[A-Za-z0-9._-]+){2,}#',
            '<path>',
            $message,
        ) ?? $message;
        // Windows-style absolute paths.
        $message = preg_replace(
            '#([A-Za-z]:\\\\[A-Za-z0-9._-]+){2,}#',
            '<path>',
            $message,
        ) ?? $message;
        return $message;
    }
}
