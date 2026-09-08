<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Closure;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Producers\TypstPreviewProducerInterface;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ephemeral counterpart to {@see TypstCompileController} — compiles
 * inline Typst source and returns the rendered bytes in the response
 * WITHOUT writing any `media_assets` or `media_derivatives` row.
 *
 * Why a separate endpoint instead of a flag on `/compile`: every render
 * previously materialised a fresh `media_assets` parent row plus a
 * derivative. Operators iterating on a document saw their playground
 * tab fill up with rows they didn't ask to keep. The Editor tab now
 * defaults to `/preview` (no DB writes) and uses `/sources` to persist
 * a source explicitly; `/compile` remains the LLM tool's surface and
 * keeps its existing "create parent + derivative" behaviour.
 *
 * Body shape (mirrors `/compile` so the frontend can share a
 * validation client):
 *
 *   {
 *     "source": "= Hello, Typst!\n",         // required, non-empty
 *     "name":   "letter.typ",                // optional, surfaced in error hints
 *     "format": "pdf" | "png" | "svg",       // optional, defaults to pdf
 *     "page":   0,                           // optional, png/svg only
 *     "ppi":    144                          // optional, png only
 *   }
 *
 * Response on success:
 *
 *   {
 *     "data": {
 *       "bytes":       "<base64>",           // the rendered PDF/PNG/SVG
 *       "mime":        "application/pdf",
 *       "format":      "pdf",
 *       "source_name": "letter.typ",
 *       "width":       612,                  // png only
 *       "height":      792                   // png only
 *     }
 *   }
 *
 * Base64 (not data: URL) so the JSON envelope stays uniform with the
 * rest of the API and the frontend can `bytes → Blob → objectURL` in
 * one place.
 *
 * Failure modes mirror `/compile`: 400 for malformed JSON, 401 for
 * unauthenticated, 422 for input-validation rejections, 503 when the
 * producer isn't registered, and 422 with structured diagnostics for
 * compile failures (same envelope as `/compile` so the frontend's
 * error parser doesn't fork).
 */
final class TypstPreviewController
{
    use JsonControllerHelpers;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
        private readonly TypstWorldFactory $worldFactory,
        /** Optional closure returning a pre-built producer. Used by tests to skip the ext-typst dependency. */
        private readonly ?Closure $producerFactory = null,
    ) {}

    /**
     * POST /api/v1/typst/preview
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $userId = $this->requireUserId();
            $inputs = (new TypstCompileInputValidator())->parseCompileInputs($request);
            $producer = $this->findProducer();
            if ($producer === null) {
                return $this->error(
                    'PRODUCER_UNAVAILABLE',
                    'TypstRenderProducer is not registered. Was the plugin boot hooked correctly?',
                    Response::HTTP_SERVICE_UNAVAILABLE,
                );
            }
            return $this->runPreview($producer, $inputs, $this->resolveContext($userId));
        } catch (CompileInputValidation $e) {
            return $e->response;
        }
    }

    private function requireUserId(): int
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new CompileInputValidation($this->unauthenticated());
        }
        return (int) $userId;
    }

    /**
     * Compile + render with no DB writes. Only one `return` per branch
     * to keep Sonar's S1142 quiet: success returns the payload,
     * diagnostics throw `TypstCompilationException` which maps to a
     * 422 envelope identical to `/compile`'s, anything else maps to a
     * generic 422.
     */
    private function runPreview(
        TypstPreviewProducerInterface $producer,
        CompileInputs $inputs,
        PrincipalContext $context,
    ): JsonResponse {
        try {
            $output = $producer->produceFromString(
                source: $inputs->source,
                format: $inputs->format,
                principalId: $context->principalId > 0 ? $context->principalId : null,
                options: array_filter([
                    'page' => $inputs->page,
                    'ppi'  => $inputs->ppi,
                ], static fn($v): bool => $v !== null),
            );
        } catch (Throwable $e) {
            return $this->previewErrorResponse($e);
        }
        return new JsonResponse(
            ['data' => $this->buildPayload($output, $inputs->name)],
            Response::HTTP_OK,
        );
    }

    /**
     * Map a thrown {@see Throwable} from the producer to the matching
     * HTTP envelope. Mirrors {@see TypstCompileController::produceErrorResponse()}
     * so the frontend's `ApiError` parser handles both endpoints with
     * one code path.
     */
    private function previewErrorResponse(Throwable $e): JsonResponse
    {
        $cause = $e->getPrevious() ?? $e;
        if ($cause instanceof TypstCompilationException) {
            return new JsonResponse(
                [
                    'error' => [
                        'code'        => 'COMPILATION_FAILED',
                        'message'     => 'Typst compilation failed',
                        'diagnostics' => TypstDiagnosticFormatter::diagnostics($cause),
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
        if ($cause instanceof InvalidArgumentException || $cause instanceof RuntimeException) {
            return $this->unprocessable('COMPILATION_FAILED', TypstDiagnosticFormatter::sanitise($cause->getMessage()));
        }
        return $this->error(
            'COMPILATION_FAILED',
            'typst preview: ' . TypstDiagnosticFormatter::sanitise($cause->getMessage()),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * @return array{bytes: string, mime: string, format: string, source_name: string, width: ?int, height: ?int}
     */
    private function buildPayload(\Spora\Services\MediaArchive\DerivativeOutput $output, string $sourceName): array
    {
        $payload = [
            'bytes'       => base64_encode($output->bytes),
            'mime'        => $output->mime,
            'format'      => $this->formatForMime($output->mime),
            'source_name' => $sourceName,
            'width'       => $output->width,
            'height'      => $output->height,
        ];
        return $payload;
    }

    /**
     * Recover the format label from the produced MIME so the frontend
     * doesn't have to redo the mime → format mapping the producer
     * already does. Defaults to `pdf` when the mime is unrecognised
     * (shouldn't happen — TypstRenderProducer's SUPPORTED_FORMATS
     * covers the three documented outputs) but a safe fallback is
     * cheaper than a thrown exception.
     */
    private function formatForMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png'       => 'png',
            'image/svg+xml'   => 'svg',
            default           => 'pdf',
        };
    }

    /**
     * Mirror {@see TypstCompileController::findProducer()}: prefer
     * the test seam (closure returning a pre-built producer), fall
     * back to discovery. TypstRenderProducer is the only producer in
     * this plugin; once instantiated via discovery, both `/compile`
     * and `/preview` share the same producer instance.
     */
    private function findProducer(): ?TypstPreviewProducerInterface
    {
        if ($this->producerFactory !== null) {
            $producer = ($this->producerFactory)();
            if (!$producer instanceof TypstPreviewProducerInterface) {
                throw new LogicException(
                    'TypstPreviewController: producer factory must return a TypstPreviewProducerInterface',
                );
            }
            return $producer;
        }
        foreach (MediaDerivativeProducerDiscovery::all() as $class) {
            if ($class === TypstRenderProducer::class) {
                return new $class($this->worldFactory);
            }
        }
        return null;
    }

    /**
     * Mirrors {@see TypstCompileController::resolveContext()} — the
     * preview's principal is the caller's user-principal, both owner
     * and runner are the caller. Uses
     * {@see PrincipalService::ensureUserPrincipal()} so the row is
     * materialised idempotently (same id every call) without forcing
     * the caller to pre-create one.
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
}
