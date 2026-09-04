<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
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
        TypstResourceStore $resourceStore,
        private readonly MediaDerivativeService $derivativeService,
        private readonly ?Closure $producerResolver = null,
        // Optional inspector seam for tests — when set, returns a
        // `\Typst\Inspector` shaped like ext-typst's without the C++
        // dependency. Production code uses the world factory's stack.
        private readonly ?Closure $inspectorFactory = null,
    ) {
        parent::__construct($worldFactory, $resourceStore);
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
            'inspect' => $this->inspectSource($arguments, $agentId, $userId, $context),
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
        $what   = isset($arguments['file']) ? 'file=' . substr((string) $arguments['file'], 0, 8) : 'inline source';
        if ($action === 'inspect') {
            return sprintf('Typst inspect (%s)', $what);
        }
        $format = strtolower((string) ($arguments['format'] ?? 'pdf'));
        return sprintf('Typst render → %s (%s)', $format, $what);
    }

    private function inspectSource(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveSource($arguments, $agentId, $userId, $context);
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
        $format = strtolower(trim((string) ($arguments['format'] ?? 'pdf')));
        if (!in_array($format, ['pdf', 'png', 'svg'], true)) {
            return new ToolResult(false, sprintf(
                'typst_compile: invalid format "%s" (expected: pdf, png, svg)',
                $format,
            ));
        }

        try {
            $resolved = $this->resolveSource($arguments, $agentId, $userId, $context);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return new ToolResult(false, $e->getMessage());
        }

        $producer = $this->findProducer();
        if ($producer === null) {
            return new ToolResult(false, 'typst_compile: TypstRenderProducer is not registered. Was the plugin boot hooked correctly?');
        }

        $output = $this->produceOrFail($producer, $resolved['parent'], $format, $arguments);
        if ($output instanceof ToolResult) {
            return $output;
        }

        $derivative = $this->persistDerivativeOrFail(
            $producer,
            $resolved['parent'],
            $output,
            $format,
            $userId,
            $context,
        );
        if ($derivative instanceof ToolResult) {
            return $derivative;
        }

        return $this->buildSuccessToolResult($derivative, $resolved['parent'], $format);
    }

    /**
     * Run {@see MediaDerivativeService::create()} and translate the
     * failure into a {@see ToolResult}. Returns the new {@see MediaAsset}
     * on success.
     *
     * @return MediaAsset|ToolResult
     */
    private function persistDerivativeOrFail(
        MediaDerivativeProducerInterface $producer,
        MediaAsset $parent,
        DerivativeOutput $output,
        string $format,
        ?int $userId,
        ?PrincipalContext $context,
    ): MediaAsset|ToolResult {
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
            return new ToolResult(false, 'typst_compile: failed to persist derivative: ' . $e->getMessage());
        }
    }

    /**
     * Run the producer and translate compile / generic failures into
     * a {@see ToolResult} when the produce call throws. Returns the
     * {@see DerivativeOutput} on success.
     *
     * @return DerivativeOutput|ToolResult
     */
    private function produceOrFail(
        MediaDerivativeProducerInterface $producer,
        MediaAsset $parent,
        string $format,
        array $arguments,
    ): DerivativeOutput|ToolResult {
        $page = isset($arguments['page']) ? max(0, (int) $arguments['page']) : null;
        $dpi  = isset($arguments['dpi']) ? max(36.0, min(600.0, (float) $arguments['dpi'])) : null;

        try {
            return $producer->produce(
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
            return new ToolResult(false, implode("\n", $lines));
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_compile: ' . $e->getMessage());
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
     * For PDF outputs, attaches a first-page PNG preview URL so the
     * chat UI's `MediaEmbed` can render the document inline; for
     * other formats the asset URL is enough.
     */
    private function buildSuccessToolResult(
        MediaAsset $derivative,
        MediaAsset $parent,
        string $format,
    ): ToolResult {
        $url = $derivative->asset_url;
        $alt = sprintf('Typst %s render of %s', strtoupper($format), $parent->filename ?? $parent->id);

        $content = $format === 'pdf'
            ? $this->pdfRenderContent($url, $alt, $parent)
            : sprintf('![%s](%s)', $alt, $url);

        return ToolResult::ok(
            content: $content,
            data: [
                'derivative_id' => $derivative->id,
                'asset_url'     => $url,
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
        return $previewUrl !== ''
            ? sprintf("[Open PDF](%s)\n\n![%s](%s)", $url, $alt, $previewUrl)
            : sprintf("[Open PDF](%s)", $url);
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
        return $pngDerivative->asset_url;
    }

    private function renderDiagnostic(string $label, object $d): string
    {
        $hints = $d->hints();
        $hintSuffix = $hints !== [] ? "\n    hint: " . implode(' / ', array_map('strval', $hints)) : '';
        return sprintf('- %s: %s%s', $label, $d->message(), $hintSuffix);
    }
}
