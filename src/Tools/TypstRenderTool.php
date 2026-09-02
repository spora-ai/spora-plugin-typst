<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use InvalidArgumentException;
use RuntimeException;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
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
    public function __construct(
        TypstWorldFactory $worldFactory,
        \Spora\Plugins\Typst\Services\TypstResourceStore $resourceStore,
        private readonly MediaDerivativeService $derivativeService,
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
        $format = strtolower(trim((string) ($arguments['format'] ?? 'pdf')));
        if (!in_array($format, ['pdf', 'png', 'svg'], true)) {
            return new ToolResult(false, sprintf(
                'typst_render: invalid format "%s" (expected: pdf, png, svg)',
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
            return new ToolResult(false, 'typst_render: TypstRenderProducer is not registered. Was the plugin boot hooked correctly?');
        }

        $page = isset($arguments['page']) ? max(0, (int) $arguments['page']) : null;
        $dpi  = isset($arguments['dpi']) ? max(36.0, min(600.0, (float) $arguments['dpi'])) : null;

        try {
            $output = $producer->produce(
                source: $resolved['parent'],
                format: $format,
                options: array_filter([
                    'page' => $page,
                    'dpi'  => $dpi,
                ], static fn($v): bool => $v !== null),
            );
        } catch (TypstCompilationException $e) {
            $lines = ['typst_render: compilation failed:'];
            foreach ($e->diagnostics as $diag) {
                $lines[] = '- ' . $diag->message();
            }
            if ($e->diagnostics === []) {
                $lines[] = $e->getMessage();
            }
            return new ToolResult(false, implode("\n", $lines));
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_render: ' . $e->getMessage());
        }

        try {
            $derivative = $this->derivativeService->create(
                parent: $resolved['parent'],
                output: $output,
                format: $format,
                producerPlugin: $producer->pluginSlug(),
                producerOperation: $producer->operationName(),
                userId: $userId,
                context: $context,
            );
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_render: failed to persist derivative: ' . $e->getMessage());
        }

        $url = $derivative->asset_url;
        $alt = sprintf('Typst %s render of %s', strtoupper($format), $resolved['parent']->filename ?? $resolved['parent']->id);

        if ($format === 'pdf') {
            // PDFs aren't image-embedable in the chat sanitizer; pair
            // the link with a first-page PNG preview so the user sees
            // the result inline. The PNG is produced via the same
            // producer path and persisted as a sibling derivative.
            $previewUrl = $this->firstPagePngUrl($resolved['parent']);
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
}
