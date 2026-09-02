<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Producers;

use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Throwable;
use Typst\Diagnostic\Severity;
use Typst\Document;
use Typst\ImageFormat;
use Typst\ImageOptions;

/**
 * {@see MediaDerivativeProducerInterface} implementation that compiles
 * Typst source (`text/x-typst` / `.typ`) into PDF / PNG / SVG.
 *
 * The producer is a leaf with no injected dependencies beyond the
 * world factory: PHP-DI autowires a fresh
 * {@see TypstWorldFactory::build()} per dispatch (which itself
 * contains ~no state — the per-call cost is one `Typst\World`
 * allocation), so each invocation gets a fresh, isolated renderer.
 *
 * Per-call flow:
 *   1. Validate `$format` is one of `pdf`, `png`, `svg` — reject early
 *      with an exception so the controller's 422 path engages.
 *   2. Load the parent asset's source bytes via the local / data-url
 *      branches (matches {@see \Spora\Services\MediaArchive\Producers\ImageDerivativeProducer::loadSourceBytes()}).
 *   3. Walk the inspector first. ext-typst's `compileString()` is
 *      forgiving enough to swallow some errors and produce a
 *      partially-broken output; the inspector's structured errors
 *      are what the LLM-facing tool wants to surface. We refuse to
 *      render when there are errors and bubble the diagnostics up as
 *      a {@see TypstCompilationException}.
 *   4. Compile and dispatch to `toPdf` / `toImage` / `toSvg` based on
 *      `$format`. The first page is rendered for `png` and `svg` by
 *      design — the agent's `typst_render` parameter `page` lets the
 *      LLM ask for a specific page; absent an explicit `page`, the
 *      first page is the most useful default.
 *
 * Idempotency is delegated to {@see \Spora\Services\MediaArchive\MediaDerivativeService::create()},
 * which keys on `(parent_id, format, producer_plugin, producer_operation)`,
 * so re-rendering the same source overwrites the existing derivative
 * row rather than stacking duplicates.
 */
final class TypstRenderProducer implements MediaDerivativeProducerInterface
{
    /**
     * Source formats the producer accepts. ext-typst has no registered
     * MIME for Typst source; `text/x-typst` is the de-facto convention
     * adopted by editor tooling and Typst's own docs.
     */
    private const SUPPORTED_SOURCE_MIMES = ['text/x-typst'];

    private const SUPPORTED_SOURCE_EXTS = ['typ'];

    /**
     * Derivative formats emitted. `pdf` is the default for
     * `typst_render` because it's the lossless container; `png` and
     * `svg` are the per-page rendering targets used by the chat UI's
     * `MediaEmbed::image()`.
     */
    private const SUPPORTED_FORMATS = ['pdf', 'png', 'svg'];

    /**
     * MIME → file-extension map for the parent's local-branch read.
     * The parent's storage token filename is built by the ingest
     * pipeline from `MediaArchiveService::extensionForMime()`, which
     * doesn't know about `text/x-typst` — so we map it ourselves
     * (matching the same approach {@see ImageDerivativeProducer} uses).
     */
    private const SOURCE_MIME_TO_EXT = [
        'text/x-typst' => 'typ',
    ];

    public function __construct(
        private readonly TypstWorldFactory $worldFactory,
    ) {}

    public function pluginSlug(): string
    {
        return 'spora-plugin-typst';
    }

    public function operationName(): string
    {
        return 'typst.render';
    }

    public function supportedSourceFormats(): array
    {
        return array_merge(self::SUPPORTED_SOURCE_MIMES, self::SUPPORTED_SOURCE_EXTS);
    }

    public function supportedDerivativeFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $format = strtolower($format);
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }

        $sourceBytes = $this->loadSourceBytes($source);
        $page = isset($options['page']) ? max(0, (int) $options['page']) : 0;

        // Build a world configured for this source's principal so
        // the per-principal template_dir / font_dirs / images are
        // visible. The principal is sourced from the source
        // MediaAsset (every render has one) rather than from a
        // constructor-time singleton, because the producer is
        // shared across principals via MediaDerivativeProducerDiscovery.
        $principalId = $source->principal_id !== null ? (int) $source->principal_id : null;
        $stack = $this->worldFactory->build($principalId);

        // Diagnostics-first: refuse to render when the inspector
        // reports errors. The producer is otherwise silent on
        // warnings — they're surfaced in the tool layer's
        // ToolResult content so the LLM can decide what to do.
        $inspection = $stack['inspector']->inspectString($sourceBytes);
        if (!$inspection->success() || $inspection->hasErrors()) {
            throw new TypstCompilationException(
                $this->summariseDiagnostics($inspection->errors()),
                $inspection->errors(),
            );
        }

        try {
            $document = $stack['compiler']->compileString($sourceBytes);
        } catch (Throwable $e) {
            throw new TypstCompilationException(
                sprintf('TypstRenderProducer: compile failed: %s', $e->getMessage()),
                [],
                $e,
            );
        }

        return match ($format) {
            'pdf' => $this->renderPdf($document),
            'png' => $this->renderPng($document, $page, $options),
            'svg' => $this->renderSvg($document, $page),
        };
    }

    private function renderPdf(Document $document): DerivativeOutput
    {
        $output = $document->toPdf();
        $bytes  = (string) $output;
        return new DerivativeOutput(
            bytes: $bytes,
            mime: 'application/pdf',
            width: null,
            height: null,
            durationSeconds: null,
        );
    }

    private function renderPng(Document $document, int $page, array $options): DerivativeOutput
    {
        $page = $this->clampPage($document, $page);
        $opts = new ImageOptions(
            format: ImageFormat::Png,
            quality: null,
            dpi: isset($options['dpi']) ? max(36.0, min(600.0, (float) $options['dpi'])) : 144.0,
        );
        $image = $document->toImage($page, $opts);
        return new DerivativeOutput(
            bytes: (string) $image,
            mime: 'image/png',
            width: $image->width(),
            height: $image->height(),
            durationSeconds: null,
        );
    }

    private function renderSvg(Document $document, int $page): DerivativeOutput
    {
        $page = $this->clampPage($document, $page);
        $svg  = $document->toSvg($page);
        return new DerivativeOutput(
            bytes: (string) $svg,
            mime: 'image/svg+xml',
            width: null,
            height: null,
            durationSeconds: null,
        );
    }

    private function clampPage(Document $document, int $page): int
    {
        $count = $document->pageCount();
        if ($count <= 0) {
            throw new TypstCompilationException('TypstRenderProducer: document has no pages');
        }
        return max(0, min($page, $count - 1));
    }

    private function loadSourceBytes(MediaAsset $asset): string
    {
        $bytes = match ($asset->storage_mode) {
            'data_url' => $this->readDataUrlBytes($asset),
            'local'    => $this->readLocalBytes($asset),
            default    => throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: storage_mode "%s" has no materialised bytes',
                (string) $asset->storage_mode,
            )),
        };
        if ($bytes === '') {
            throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: MediaAsset %s has empty source bytes',
                $asset->id,
            ));
        }
        return $bytes;
    }

    private function readDataUrlBytes(MediaAsset $asset): string
    {
        $payload = $asset->payload;
        if (!is_string($payload) || $payload === '') {
            throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: MediaAsset %s has empty data_url payload',
                $asset->id,
            ));
        }
        return $payload;
    }

    private function readLocalBytes(MediaAsset $asset): string
    {
        $token = $asset->asset_token;
        if (!is_string($token) || $token === '') {
            throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: MediaAsset %s has no asset_token',
                $asset->id,
            ));
        }
        $mime = strtolower((string) $asset->mime_type);
        $ext  = self::SOURCE_MIME_TO_EXT[$mime] ?? MediaArchiveService::extensionForMime($mime);
        if ($ext === null) {
            throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: cannot derive file extension for source MIME "%s"',
                $mime,
            ));
        }

        // The asset's on-disk location is `<storage>/assets/<token>.<ext>`
        // (matches ImageDerivativeProducer::readLocalBytes()'s path).
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $paths    = new \Spora\Core\Paths($basePath);
        $path     = $paths->storage('assets') . '/' . $token . '.' . $ext;

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $bytes = file_get_contents($path);
        } finally {
            restore_error_handler();
        }
        if (!is_string($bytes)) {
            throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: MediaAsset %s local file unreadable: %s',
                $asset->id,
                $path,
            ));
        }
        return $bytes;
    }

    /**
     * Render the inspector's diagnostic list as a single-line
     * summary suitable for the exception message. The full
     * diagnostics remain available via
     * {@see TypstCompilationException::$diagnostics} so the tool
     * layer can emit the structured form.
     *
     * @param list<\Typst\Diagnostic\Diagnostic> $diagnostics
     */
    private function summariseDiagnostics(array $diagnostics): string
    {
        if ($diagnostics === []) {
            return 'TypstRenderProducer: compilation produced no document';
        }
        $lines = [];
        foreach ($diagnostics as $d) {
            if ($d->severity() !== Severity::Error) {
                continue;
            }
            $lines[] = '- ' . $d->message();
        }
        if ($lines === []) {
            return 'TypstRenderProducer: compilation produced errors (see diagnostics)';
        }
        return "TypstRenderProducer: " . implode("\n", $lines);
    }
}
