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
 *     "format": "pdf" | "png" | "svg",     // default pdf
 *     "page":   0,                          // png only (0-indexed)
 *     "dpi":    144                         // png only (36..600)
 *   }
 *
 * The controller materialises an inline `text/x-typst` parent row so
 * the natural-key on `media_derivatives` is well-defined (the producer
 * operates on a {@see MediaAsset}, not raw bytes) and re-renders are
 * idempotent: hitting the endpoint twice with the same source overwrites
 * the existing derivative's bytes rather than stacking rows.
 *
 * For PDF the controller also produces a first-page PNG sibling so the
 * UI can render an inline preview without a second round-trip — the
 * chat UI's `MediaEmbed` does the same dance for tool responses.
 */
final class TypstCompileController
{
    use JsonControllerHelpers;

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
            $parent = $this->materialiseInlineSource($source, $context);
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
                $diagnostics[] = ['message' => $diag->message()];
            }
            if ($diagnostics === []) {
                $diagnostics[] = ['message' => $e->getMessage()];
            }
            return new JsonResponse(
                ['error' => ['code' => 'COMPILATION_FAILED', 'message' => 'Typst compilation failed', 'diagnostics' => $diagnostics]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->unprocessable('COMPILATION_FAILED', $e->getMessage());
        } catch (Throwable $e) {
            return $this->error(
                'COMPILATION_FAILED',
                'typst compile: ' . $e->getMessage(),
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
     * For inline `source`, persist a transient parent row so
     * `MediaDerivativeService::create()` can record the natural-key
     * link `(parent_id, format, producer_plugin, producer_operation)`.
     * The parent is a `text/x-typst` MediaAsset with `storage_mode =
     * data_url` so the producer's read path picks up the source bytes
     * directly without writing a duplicate to disk.
     *
     * Mirrors {@see \Spora\Plugins\Typst\Tools\AbstractTypstTool::materialiseInlineSource()}
     * with a different `tool_name` (`typst.playground` vs `typst.render`)
     * so operator-driven renders and agent-driven renders are separable
     * in audit/log queries.
     */
    private function materialiseInlineSource(string $source, PrincipalContext $context): MediaAsset
    {
        $id = self::generateUuid();
        $asset = new MediaAsset();
        $asset->id            = $id;
        $asset->user_id       = $context->ownerUserId;
        $asset->agent_id      = null;
        $asset->principal_id  = $context->principalId > 0 ? $context->principalId : null;
        $asset->plugin_slug   = 'spora-plugin-typst';
        $asset->tool_name     = 'typst.playground';
        $asset->mime_type     = 'text/x-typst';
        $asset->media_type    = MediaType::Document->value;
        $asset->byte_size     = strlen($source);
        $asset->filename      = 'playground.typ';
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
}
