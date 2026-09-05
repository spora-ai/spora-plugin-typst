<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Exceptions\TypstInvalidArgumentException;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Compile a Typst source document — either produce a PDF/PNG/SVG
 * derivative (`action: render`) or run a read-only inspector pass
 * (`action: inspect`) for fast feedback before committing to a render.
 *
 * The previous design shipped these as two separate tools
 * (`TypstRenderTool`, `TypstInspectTool`), each declaring `#[Tool]` and
 * `#[ToolParameter]` but no `#[ToolOperation]`. {@see \Spora\Agents\ToolDefinitionBuilder}
 * routes tools through `buildOperationToolDefinition()` and returns
 * `null` when `getOperations()` is empty, so the LLM-facing schema
 * silently dropped both tools. They appeared on `/api/v1/tools` (the
 * admin endpoint reads `#[Tool]` directly) but were invisible to the
 * LLM's function-call surface, which made the bug hard to spot.
 *
 * Now: one tool with two `#[ToolOperation]` declarations, with the
 * render op requiring approval (it persists a media-derivative row)
 * and the inspect op auto-approved (no side effects).
 *
 * Usage from the LLM:
 *
 *   typst_compile(action: "render", source: "= Hello\n", format: "pdf")
 *   typst_compile(action: "render", file: "<media_uuid>", format: "png", page: 0, dpi: 200)
 *   typst_compile(action: "inspect", source: "= Hello\n")
 */
#[Tool(
    name: 'typst_compile',
    description: 'Compile Typst source: action=render produces a PDF/PNG/SVG media-derivative; action=inspect runs a read-only error-only pass without producing output. Provide source as an inline string OR a media asset id (the .typ file).',
    displayName: 'Typst Compile',
    category: 'generation',
    icon: 'file-text',
)]
#[ToolOperation(
    name: 'render',
    description: 'Compile Typst source to PDF/PNG/SVG using ext-typst and persist the result as a media-derivative. format: pdf (default) | png | svg. Provide source as inline string or `file` (media asset id of a .typ file).',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'inspect',
    description: 'Run a read-only inspector pass on Typst source. Returns the structured error and warning list from ext-typst\'s Inspector without producing any output or media-derivative. Cheaper than render — use as a first pass on a new source. Provide source as inline string or `file` (media asset id of a .typ file).',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'source',
    type: 'string',
    description: 'Inline Typst source. Provide one of `source` or `file`.',
    required: ['render', 'inspect'],
)]
#[ToolParameter(
    name: 'file',
    type: 'string',
    description: 'Media asset id of a previously-uploaded .typ file. Provide one of `source` or `file`.',
    required: ['render', 'inspect'],
)]
#[ToolParameter(
    name: 'format',
    type: 'string',
    description: 'Output format: pdf (default) | png | svg. Ignored when action=inspect.',
    required: false,
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional basename for the playground parent row when rendering inline `source` (a name like "letter.typ"; `.typ` is auto-appended). Ignored when `file` is supplied. When omitted, the tool auto-generates a unique `inline-YYYYMMDD-HHMMSS-XXXX.typ` name so each render still surfaces in the playground picker without the LLM having to invent a basename. Distinct from `file` (a media asset UUID) — `file` references an uploaded row, `filename` labels the row materialised from inline source.',
    required: false,
)]
#[ToolParameter(
    name: 'page',
    type: 'integer',
    description: 'Page number to render for png/svg (0-indexed; default 0). Ignored when action=inspect or format=pdf.',
    required: false,
)]
#[ToolParameter(
    name: 'dpi',
    type: 'number',
    description: 'DPI for png output (36-600; default 144). Ignored when action=inspect, format=pdf, or format=svg.',
    required: false,
)]
final class TypstCompileTool extends AbstractTypstTool
{
    public function __construct(
        TypstWorldFactory $worldFactory,
        private readonly MediaDerivativeService $derivativeService,
        private readonly ?Closure $producerResolver = null,
        // Optional inspector seam for tests — when set, returns a
        // `\Typst\Inspector` shaped like ext-typst's without the C++
        // dependency. Production code uses the world factory's stack.
        private readonly ?Closure $inspectorFactory = null,
    ) {
        parent::__construct($worldFactory);
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $action = $this->resolveAction($arguments);

        return match ($action) {
            'inspect' => $this->inspectSource($arguments, $userId, $context),
            'render', '' => $this->renderSource($arguments, $agentId, $userId, $context),
            default    => new ToolResult(false, sprintf(
                'typst_compile: unknown action "%s" (expected: render | inspect)',
                $action,
            )),
        };
    }

    public function describeAction(array $arguments): string
    {
        $action = $this->resolveAction($arguments);
        $what = $this->describeSource($arguments);
        if ($action === 'inspect') {
            return sprintf('Typst inspect (%s)', $what);
        }
        $format = strtolower((string) ($arguments['format'] ?? 'pdf'));
        return sprintf('Typst render → %s (%s)', $format, $what);
    }

    /**
     * Build the source descriptor shown in {@see describeAction()}.
     * For `file=<id>` shows the file prefix; for inline `source`
     * shows the supplied `filename` when one is given (so the
     * approval UI surfaces the row name the LLM picked), or the
     * literal "inline" when no filename is supplied.
     */
    private function describeSource(array $arguments): string
    {
        if (isset($arguments['file']) && is_string($arguments['file']) && $arguments['file'] !== '') {
            return 'file=' . substr($arguments['file'], 0, 8);
        }
        $rawName = $arguments['filename'] ?? null;
        if (is_string($rawName) && $rawName !== '') {
            $name = trim($rawName);
            return $name === '' ? 'inline' : $name;
        }
        return 'inline';
    }

    private function inspectSource(array $arguments, ?int $userId, ?PrincipalContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveSourceBytes($arguments, $context, $userId);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return new ToolResult(false, $e->getMessage());
        }

        try {
            $inspector = $this->inspectorFactory !== null
                ? ($this->inspectorFactory)()
                : $this->worldFactory->build($context?->principalId)['inspector'];
            $result = $inspector->inspectString($resolved['bytes']);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_compile: inspect failed: ' . $e->getMessage());
        }

        $errors   = $result->errors();
        $warnings = $result->warnings();

        $lines = [];
        if ($errors === [] && $warnings === []) {
            $lines[] = 'typst_compile: no diagnostics (the source parses cleanly under the inspector)';
        } else {
            $lines[] = sprintf(
                'typst_compile: %d error(s), %d warning(s)',
                count($errors),
                count($warnings),
            );
            foreach ($errors as $d) {
                $lines[] = $this->renderDiagnostic('error', $d);
            }
            foreach ($warnings as $d) {
                $lines[] = $this->renderDiagnostic('warning', $d);
            }
        }

        return ToolResult::ok(
            content: implode("\n", $lines),
            data: [
                'errors'   => array_map(static fn($d): array => [
                    'severity' => $d->severity()->name,
                    'message'  => $d->message(),
                    'hints'    => array_map(static fn($h): string => (string) $h, $d->hints()),
                ], $errors),
                'warnings' => array_map(static fn($d): array => [
                    'severity' => $d->severity()->name,
                    'message'  => $d->message(),
                    'hints'    => array_map(static fn($h): string => (string) $h, $d->hints()),
                ], $warnings),
                'success'  => $result->success(),
            ],
        );
    }

    private function renderSource(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context): ToolResult
    {
        try {
            $format = strtolower(trim((string) ($arguments['format'] ?? 'pdf')));
            if (!in_array($format, ['pdf', 'png', 'svg'], true)) {
                throw new TypstInvalidArgumentException(sprintf(
                    'typst_compile: invalid format "%s" (expected: pdf, png, svg)',
                    $format,
                ));
            }

            $resolved = $this->resolveSourceForRender($arguments, $agentId, $userId, $context);
            $producer = $this->findProducer();
            if ($producer === null) {
                throw new TypstRuntimeException('typst_compile: TypstRenderProducer is not registered. Was the plugin boot hooked correctly?');
            }
            $derivative = $this->producePersistOrThrow(
                $producer,
                $resolved['parent'],
                $format,
                $arguments,
                $userId,
                $context,
            );
            return $this->buildSuccessToolResult($derivative, $resolved['parent'], $format);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return new ToolResult(false, $e->getMessage());
        }
    }

    /**
     * Run {@see MediaDerivativeProducerInterface::produce()} and
     * {@see MediaDerivativeService::create()} back to back. On any
     * failure, throw — the caller translates the throw into a
     * {@see ToolResult} so the error path stays in one place.
     */
    private function producePersistOrThrow(
        MediaDerivativeProducerInterface $producer,
        MediaAsset $parent,
        string $format,
        array $arguments,
        ?int $userId,
        ?PrincipalContext $context,
    ): MediaAsset {
        $page = isset($arguments['page']) ? max(0, (int) $arguments['page']) : null;
        $dpi  = isset($arguments['dpi']) ? max(36.0, min(600.0, (float) $arguments['dpi'])) : null;

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
            $lines = ['typst_compile: compilation failed:'];
            foreach ($e->diagnostics as $diag) {
                $lines[] = '- ' . $diag->message();
            }
            if ($e->diagnostics === []) {
                $lines[] = $e->getMessage();
            }
            throw new TypstRuntimeException(implode("\n", $lines), $e);
        } catch (Throwable $e) {
            throw new TypstRuntimeException('typst_compile: ' . $e->getMessage(), $e);
        }

        try {
            return $this->derivativeService->create(
                parent: $parent,
                output: $output,
                format: $format,
                producerPlugin: $producer->pluginSlug(),
                producerOperation: $producer->operationName(),
                userId: $userId,
                context: $context,
            );
        } catch (Throwable $e) {
            throw new TypstRuntimeException('typst_compile: failed to persist derivative: ' . $e->getMessage(), $e);
        }
    }

    private function findProducer(): ?MediaDerivativeProducerInterface
    {
        if ($this->producerResolver !== null) {
            return ($this->producerResolver)();
        }
        foreach (MediaDerivativeProducerDiscovery::all() as $class) {
            if ($class === TypstRenderProducer::class) {
                return new $class($this->worldFactory);
            }
        }
        return null;
    }

    /**
     * Build the success {@see ToolResult} for a successful render.
     *
     * Mirrors `OpenAIImageGenerationTool::renderResponse()`: a heading
     * summarising the deliverable, a markdown block the chat UI
     * renders inline, and a trailing instruction telling the LLM
     * where to find the raw URLs (the `data.asset_urls` channel)
     * instead of inventing its own. The instruction explicitly bars
     * `file://` and external-domain URLs because LLMs hallucinate
     * both when asked to "embed" or "link to" the result in a
     * follow-up call.
     *
     * For PDF outputs the markdown block is a `[Open PDF](url)` link
     * paired with a first-page PNG preview rendered inline. For PNG
     * and SVG it's a single markdown image.
     *
     * `asset_urls` is the authoritative URL channel; the previous
     * `asset_url` (singular string) was removed because the LLM has
     * no reliable way to know whether a tool field is a string or a
     * list, and the OpenAI plugin already standardised on a plural
     * list (`image_urls`). The HTTP controller payload keeps
     * `asset_url` because the typst-frontend binds to it.
     */
    private function buildSuccessToolResult(
        MediaAsset $derivative,
        MediaAsset $parent,
        string $format,
    ): ToolResult {
        $url = $derivative->asset_url;
        $alt = sprintf('Typst %s render of %s', strtoupper($format), $parent->filename ?? $parent->id);

        $body = $format === 'pdf'
            ? $this->pdfRenderContent($url, $alt, $parent)
            : MediaEmbed::image($url, $alt);

        $content = sprintf(
            "Rendered %s\n\n%s\n\n%s",
            strtoupper($format),
            $body,
            $this->echoInstruction(),
        );

        return ToolResult::ok(
            content: $content,
            data: [
                'derivative_id' => $derivative->id,
                'asset_urls'    => [$url],
                'format'        => $format,
                'mime'          => $derivative->mime_type,
                'size'          => $derivative->byte_size,
                'width'         => $derivative->width,
                'height'        => $derivative->height,
            ],
        );
    }

    /**
     * PDFs aren't image-embedable in the chat sanitizer; pair the
     * link with a first-page PNG preview so the user sees the result
     * inline. The PNG is produced via the same producer path and
     * persisted as a sibling derivative.
     */
    private function pdfRenderContent(string $url, string $alt, MediaAsset $parent): string
    {
        $previewUrl = $this->firstPagePngUrl($parent);
        if ($previewUrl === '') {
            return sprintf('[Open PDF](%s)', $url);
        }
        return sprintf(
            "[Open PDF](%s)\n\n%s",
            $url,
            MediaEmbed::image($previewUrl, $alt . ' (first-page preview)'),
        );
    }

    /**
     * Trailing instruction for every successful render. Tells the LLM
     * to echo the markdown block above verbatim so the chat UI
     * renders it inline, and to read raw URLs from
     * `ToolResult.data.asset_urls` rather than constructing its own
     * — the explicit `file://` / external-domain warning is the
     * preventative half: LLMs that try to embed the result in a
     * follow-up call otherwise hallucinate `file:///tmp/...` or
     * `https://example.com/...` URLs that don't resolve.
     */
    private function echoInstruction(): string
    {
        return 'Echo the markdown block above verbatim so the chat UI renders the result inline. '
            . 'For raw URLs (e.g. to embed in a follow-up tool call), read ToolResult.data.asset_urls. '
            . 'Do NOT invent or rewrite URLs — `file://` and external domains are unsupported; '
            . 'the data channel is the only authoritative source.';
    }

    /**
     * Render a first-page PNG sibling so the chat UI's `MediaEmbed`
     * shows a preview. Returns the preview asset URL or empty string
     * when the producer or persistence path fails — the parent
     * render still succeeds; the preview is best-effort.
     */
    private function firstPagePngUrl(MediaAsset $parent): string
    {
        $producer = $this->findProducer();
        if ($producer === null) {
            return '';
        }
        try {
            $png = $producer->produce($parent, 'png', ['page' => 0, 'dpi' => 144.0]);
            $pngDerivative = $this->derivativeService->create(
                parent: $parent,
                output: $png,
                format: 'png',
                producerPlugin: $producer->pluginSlug(),
                producerOperation: $producer->operationName(),
                userId: null,
                context: null,
            );
            return $pngDerivative->asset_url;
        } catch (Throwable) {
            return '';
        }
    }

    private function renderDiagnostic(string $label, object $d): string
    {
        $hints = $d->hints();
        $hintSuffix = $hints !== [] ? "\n    hint: " . implode(' / ', array_map('strval', $hints)) : '';
        return sprintf('- %s: %s%s', $label, $d->message(), $hintSuffix);
    }
}
