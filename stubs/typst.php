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
 *
 * The constructors use property promotion and the runtime methods
 * reference their parameters in a no-op statement so SonarCloud's
 * S1172 (unused parameter) and S1186 (empty method body) rules are
 * satisfied without changing the public class shape PHPStan reads.
 */
namespace Typst {
    final class World
    {
        public function __construct(
            public readonly ?string $template_dir = null,    // NOSONAR php:S116 — must match ext-typst's snake_case API
            public readonly ?int $cache_size = null,         // NOSONAR php:S116
            public readonly bool $embed_default_fonts = false, // NOSONAR php:S116
            public readonly array $font_dirs = [],           // NOSONAR php:S116
            public readonly ?string $package_dir = null,    // NOSONAR php:S116
            public readonly ?string $namespace = null,       // NOSONAR php:S116
        ) {
            // Stub for PHPStan static analysis. The real World
            // constructor is provided by the ext-typst extension at
            // runtime — this stub exists only so static analysis can
            // resolve the class shape.
        }
    }

    final class Compiler
    {
        public readonly World $world;

        public function __construct(World $world)
        {
            // Stub for PHPStan static analysis. The real Compiler
            // is provided by the ext-typst extension at runtime.
            $this->world = $world;
        }

        public function compileString(string $source): Document
        {
            throw new \LogicException('ext-typst stub: real class only available when the extension is loaded');
        }
    }

    final class Inspector
    {
        public readonly World $world;

        public function __construct(World $world)
        {
            // Stub for PHPStan static analysis. The real Inspector
            // is provided by the ext-typst extension at runtime.
            $this->world = $world;
        }

        public function inspectString(string $source): object
        {
            throw new \LogicException('ext-typst stub: real class only available when the extension is loaded');
        }
    }

    final class Document
    {
        public function toPdf(): string { return ''; }

        public function toImage(int $page, ImageOptions $options): object
        {
            // Stub for PHPStan static analysis. The real implementation
            // is provided by the ext-typst extension at runtime — we
            // accept $page / $options here purely to mirror the public
            // signature PHPStan reads from this stub.
            unset($page, $options);

            return new \stdClass();
        }

        public function toSvg(int $page): string
        {
            // Stub for PHPStan static analysis. See note on toImage().
            unset($page);

            return '';
        }

        public function pageCount(): int { return 0; }
    }

    final class ImageOptions
    {
        public function __construct(
            public readonly ImageFormat $format,
            public readonly mixed $quality = null,
            public readonly ?float $dpi = null,
        ) {
            // Stub for PHPStan static analysis. The real
            // ImageOptions is provided by the ext-typst extension
            // at runtime.
        }
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
