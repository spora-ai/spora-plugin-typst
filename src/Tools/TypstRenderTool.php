<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use Closure;
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
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Compile a Typst source document into PDF / PNG / SVG and surface
 * the derivative via {@see \Spora\Tools\MediaEmbed}.
 *
 * The tool is the agent-facing wrapper around
 * {@see TypstRenderProducer}: the producer is a leaf that compiles
 * bytes to bytes; this tool wires it into
 * {@see MediaDerivativeService::create()} so the derivative lands in
 * the media library as a fresh `media_assets` row, and returns the
 * canonical `/api/v1/assets/<uuid>.<ext>` URL the chat UI knows how
 * to render.
 *
 * Usage from the LLM:
 *
 *   typst_render(source: "= Hello\n", format: "pdf")
 *   typst_render(file: "<media_uuid>", format: "png", page: 0, dpi: 200)
 *
 *   Inline sources are stored as transient `text/x-typst` parent
 *   rows so the natural-key on `media_derivatives` (parent_id,
 *   format, producer_plugin, producer_operation) is well-defined.
 *   Re-rendering the same (parent, format) pair is idempotent and
 *   refreshes the existing derivative row.
 */
#[Tool(
    name: 'typst_render',
    description: 'Compile a Typst source document to PDF, PNG, or SVG using ext-typst. Provide source as an inline string OR a media asset id (the .typ file). The result is stored as a media-derivative and returned as an image/PDF embed.',
    displayName: 'Typst Render',
    category: 'generation',
    icon: 'file-text',
)]
#[ToolParameter(
    name: 'source',
    type: 'string',
    description: 'Inline Typst source to compile. Provide one of `source` or `file`.',
    required: false,
)]
#[ToolParameter(
    name: 'file',
    type: 'string',
    description: 'Media asset id of a previously-uploaded .typ file to compile. Provide one of `source` or `file`.',
    required: false,
)]
#[ToolParameter(
    name: 'format',
    type: 'string',
    description: 'Output format: pdf (default) | png | svg.',
    required: false,
)]
#[ToolParameter(
    name: 'page',
    type: 'integer',
    description: 'Page number to render for png/svg (0-indexed; default 0). Ignored for pdf.',
    required: false,
)]
#[ToolParameter(
    name: 'dpi',
    type: 'number',
    description: 'DPI for png output (36-600; default 144). Ignored for pdf and svg.',
    required: false,
)]
final class TypstRenderTool extends AbstractTypstTool
{
    /**
     * @var (Closure(): ?MediaDerivativeProducerInterface)|null
     */
    private readonly ?Closure $producerResolver;

    public function __construct(
        TypstWorldFactory $worldFactory,
        \Spora\Plugins\Typst\Services\TypstResourceStore $resourceStore,
        private readonly MediaDerivativeService $derivativeService,
        ?Closure $producerResolver = null,
    ) {
        parent::__construct($worldFactory, $resourceStore);
        $this->producerResolver = $producerResolver;
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        try {
            $format = $this->validateFormat($arguments['format'] ?? 'pdf');
            $resolved = $this->resolveSourceOrFail($arguments, $agentId, $userId, $context);
            $producer = $this->findProducer();
            if ($producer === null) {
                throw new RenderToolFailed('typst_render: TypstRenderProducer is not registered. Was the plugin boot hooked correctly?');
            }
            $output = $this->safeProduce($producer, $resolved['parent'], $format, $arguments);
            $derivative = $this->safePersistDerivative(
                $producer,
                $resolved['parent'],
                $output,
                $format,
                $userId,
                $context,
            );
        } catch (RenderToolFailed $e) {
            return new ToolResult(false, $e->getMessage());
        }

        return $this->buildSuccessToolResult($derivative, $resolved['parent'], $format);
    }

    private function validateFormat(mixed $raw): string
    {
        $format = strtolower(trim((string) $raw));
        if (!in_array($format, ['pdf', 'png', 'svg'], true)) {
            throw new RenderToolFailed(sprintf(
                'typst_render: invalid format "%s" (expected: pdf, png, svg)',
                $format,
            ));
        }
        return $format;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{bytes: string, parent: MediaAsset}
     */
    private function resolveSourceOrFail(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context): array
    {
        try {
            return $this->resolveSource($arguments, $agentId, $userId, $context);
        } catch (TypstInvalidArgumentException | TypstRuntimeException $e) {
            throw new RenderToolFailed($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function safeProduce(
        MediaDerivativeProducerInterface $producer,
        MediaAsset $parent,
        string $format,
        array $arguments,
    ): mixed {
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
            throw new RenderToolFailed($this->formatCompilationFailure($e));
        } catch (Throwable $e) {
            throw new RenderToolFailed('typst_render: ' . $e->getMessage());
        }
    }

    private function safePersistDerivative(
        MediaDerivativeProducerInterface $producer,
        MediaAsset $parent,
        mixed $output,
        string $format,
        ?int $userId,
        ?PrincipalContext $context,
    ): mixed {
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
            throw new RenderToolFailed('typst_render: failed to persist derivative: ' . $e->getMessage());
        }
    }

    private function formatCompilationFailure(TypstCompilationException $e): string
    {
        $lines = ['typst_render: compilation failed:'];
        foreach ($e->diagnostics as $diag) {
            $lines[] = '- ' . $diag->message();
        }
        if ($e->diagnostics === []) {
            $lines[] = $e->getMessage();
        }
        return implode("\n", $lines);
    }

    private function buildSuccessToolResult(mixed $derivative, MediaAsset $parent, string $format): ToolResult
    {
        $url = $derivative->asset_url;
        $alt = sprintf('Typst %s render of %s', strtoupper($format), $parent->filename ?? $parent->id);

        if ($format === 'pdf') {
            // PDFs aren't image-embedable in the chat sanitizer; pair
            // the link with a first-page PNG preview so the user sees
            // the result inline. The PNG is produced via the same
            // producer path and persisted as a sibling derivative.
            $previewUrl = $this->firstPagePngUrl($parent);
            $content = $previewUrl !== ''
                ? sprintf("[Open PDF](%s)\n\n![%s](%s)", $url, $alt, $previewUrl)
                : sprintf("[Open PDF](%s)", $url);
        } else {
            $content = sprintf('![%s](%s)', $alt, $url);
        }

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

    public function describeAction(array $arguments): string
    {
        $format = strtolower((string) ($arguments['format'] ?? 'pdf'));
        $what   = isset($arguments['file']) ? 'file=' . substr((string) $arguments['file'], 0, 8) : 'inline source';
        return sprintf('Typst render → %s (%s)', $format, $what);
    }

    private function findProducer(): ?MediaDerivativeProducerInterface
    {
        // Tests inject a producer resolver to bypass the discovery
        // registry when ext-typst isn't loaded (the real producer's
        // `produce()` requires the extension). Production code uses
        // the default discovery lookup.
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
     * When the requested format is PDF, render the first page as PNG
     * alongside it so the chat UI's `MediaEmbed` shows a preview.
     * The PNG is stored as a separate derivative under the same
     * parent so it shows up in the Versions UI too.
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
        } catch (Throwable) {
            return '';
        }
        return $pngDerivative->asset_url;
    }
}

/**
 * Internal control-flow exception thrown by helpers in
 * {@see TypstRenderTool::execute()} to unwind request handling
 * without piling up `return new ToolResult(false, ...)` statements
 * (which Sonar's S1142 counts). Carries the message
 * {@see execute()} wraps into a {@see ToolResult::ok()}-fail result.
 */
final class RenderToolFailed extends RuntimeException {}
