<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Producers;

use Closure;
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
 *      design — the agent's `typst_compile(action: "render")` parameter
 *      `page` lets the LLM ask for a specific page; absent an explicit
 *      `page`, the first page is the most useful default.
 *
 * Idempotency is delegated to {@see \Spora\Services\MediaArchive\MediaDerivativeService::create()},
 * which keys on `(parent_id, format, producer_plugin, producer_operation)`,
 * so re-rendering the same source overwrites the existing derivative
 * row rather than stacking duplicates.
 *
 * Also satisfies {@see TypstPreviewProducerInterface} so the Editor
 * tab's `/preview` endpoint can reuse the same compile + render
 * pipeline without going through a MediaAsset — see
 * {@see produceFromString()} for the rationale.
 */
final class TypstRenderProducer implements MediaDerivativeProducerInterface, TypstPreviewProducerInterface
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
     * `typst_compile(action: "render")` because it's the lossless container;
     * `png` and `svg` are the per-page rendering targets used by the chat
     * UI's `MediaEmbed::image()`.
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
        // Test seam: returns the (world, compiler, inspector) stack
        // directly so tests can stub the ext-typst-backed compile path.
        // Production code leaves this null and uses the real factory.
        private readonly ?Closure $stackFactory = null,
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
        $format = $this->assertSupportedFormat($format);

        return $this->compileAndRender(
            bytes: $this->loadSourceBytes($source),
            format: $format,
            principalId: $source->principal_id !== null ? (int) $source->principal_id : null,
            options: $options,
        );
    }

    /**
     * Ephemeral render surface for {@see \Spora\Plugins\Typst\Http\TypstPreviewController}.
     * Skips the `MediaAsset` load path entirely — the preview endpoint
     * never persists the source, so wrapping it in a MediaAsset row
     * would be ceremony for the sake of an interface signature. Takes
     * the principal id directly so the world factory can still scope
     * `template_dir` / `font_dirs` to the caller's principal without a
     * transient `media_assets` row.
     *
     * Same inspector-first discipline as {@see produce()}: throws
     * {@see TypstCompilationException} on diagnostics errors so the
     * preview controller's 422 path engages with the same envelope
     * shape the {@see TypstCompileController} uses for `/compile`.
     */
    public function produceFromString(string $source, string $format, ?int $principalId, array $options = []): DerivativeOutput
    {
        $format = $this->assertSupportedFormat($format);

        return $this->compileAndRender(
            bytes: $source,
            format: $format,
            principalId: $principalId,
            options: $options,
        );
    }

    private function assertSupportedFormat(string $format): string
    {
        $format = strtolower($format);
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: unsupported derivative format "%s" (supported: %s)',
                $format,
                implode(', ', self::SUPPORTED_FORMATS),
            ));
        }
        return $format;
    }

    /**
     * Shared compile → render pipeline for both {@see produce()} and
     * {@see produceFromString()}. Splits the world-building,
     * inspector, compile, and format-dispatch steps out of the public
     * methods so the MediaAsset-shaped and string-shaped entry points
     * stay symmetric — adding a third surface (e.g. an in-memory
     * document passed in by a test fixture) wouldn't need to copy
     * the inspector-first logic.
     *
     * `page` / `dpi` / `principalId` are extracted here because both
     * public surfaces normalise them from their respective sources
     * (`produce()` from the MediaAsset, `produceFromString()` from
     * the caller's argument list) before reaching this point.
     */
    private function compileAndRender(string $bytes, string $format, ?int $principalId, array $options): DerivativeOutput
    {
        $page = isset($options['page']) ? max(0, (int) $options['page']) : 0;

        // Build a world configured for the caller so the
        // per-principal template_dir / font_dirs / images are
        // visible. The producer is shared across principals via
        // MediaDerivativeProducerDiscovery — the principal is passed
        // per-call rather than at construction time.
        $stack = $this->stackFactory !== null
            ? ($this->stackFactory)($principalId)
            : $this->worldFactory->build($principalId);

        // Wrap with the factory's prelude so a document without an
        // explicit `#set text(font: …)` (or math-mode setup) still
        // renders; user-authored set/show rules later in the file
        // override the prelude's defaults.
        $wrapped = $this->worldFactory->wrapSource($bytes);

        // Diagnostics-first: refuse to render when the inspector
        // reports errors. The producer is otherwise silent on
        // warnings — they're surfaced in the tool layer's
        // ToolResult content so the LLM can decide what to do.
        $inspection = $stack['inspector']->inspectString($wrapped);
        if (!$inspection->success() || $inspection->hasErrors()) {
            throw new TypstCompilationException(
                $this->summariseDiagnostics($inspection->errors()),
                $inspection->errors(),
            );
        }

        try {
            $document = $stack['compiler']->compileString($wrapped);
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
            // Unreachable — {@see assertSupportedFormat()} gates
            // every public entry point — but PHPStan needs an
            // exhaustive match and throwing here surfaces a
            // programmer error rather than silently dropping it.
            default => throw new TypstRuntimeException(sprintf(
                'TypstRenderProducer: format "%s" not handled by compileAndRender() — assertSupportedFormat() regression?',
                $format,
            )),
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
