<?php

declare(strict_types=1);

/**
 * ext-typst PHPStan stubs.
 *
 * These stubs shadow the real ext-typst classes when the extension
 * isn't installed (which is the case on vanilla ubuntu CI runners
 * — see `.github/workflows/ci.yml`). PHPStan reads them via the
 * `scanDirectories` block in `phpstan.neon` and treats every
 * `Typst\…` reference as a real (but unanalyzed) class, so the
 * static-analysis job stays green when PECL can't fetch the
 * extension.
 *
 * Mirrors the surface the plugin actually touches — adding a new
 * `Typst\…` import here keeps the stub list honest.
 *
 * Keep this file in lockstep with `composer.json → require:
 * ext-typst`: any new plugin code that pulls in a class not listed
 * here will surface as a "class not found" PHPStan error on CI
 * until the corresponding stub is added.
 */
namespace Typst {
    final class World
    {
        public function __construct(
            ?string $template_dir = null,
            ?int $cache_size = null,
            bool $embed_default_fonts = false,
            array $font_dirs = [],
            ?string $package_dir = null,
            ?string $namespace = null,
        ) {}
    }

    final class Compiler
    {
        public function __construct(World $world) {}

        public function compileString(string $source): Document
        {
            throw new \LogicException('ext-typst stub: real class only available when the extension is loaded');
        }
    }

    final class Inspector
    {
        public function __construct(World $world) {}

        public function inspectString(string $source): object
        {
            throw new \LogicException('ext-typst stub: real class only available when the extension is loaded');
        }
    }

    final class Document
    {
        public function toPdf(): string { return ''; }
        public function toImage(int $page, ImageOptions $options): object { return new \stdClass(); }
        public function toSvg(int $page): string { return ''; }
        public function pageCount(): int { return 0; }
    }

    final class ImageOptions
    {
        public function __construct(
            public readonly ImageFormat $format,
            public readonly mixed $quality = null,
            public readonly ?float $dpi = null,
        ) {}
    }

    enum ImageFormat
    {
        case Png;
    }
}

namespace Typst\Diagnostic {
    final class Diagnostic
    {
        public function severity(): Severity
        {
            throw new \LogicException('ext-typst stub');
        }
        public function message(): string
        {
            return '';
        }
        /**
         * @return list<string>
         */
        public function hints(): array
        {
            return [];
        }
    }

    enum Severity
    {
        case Error;
        case Warning;
        case Hint;
    }
}
