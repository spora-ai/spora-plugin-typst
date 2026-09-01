<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use RuntimeException;
use Typst\Compiler;
use Typst\Inspector;
use Typst\World;

/**
 * Builds a {@see World} configured for a principal's view of the
 * plugin's font + example directories, plus a paired
 * {@see Compiler}/{@see Inspector} that share the world.
 *
 * The world's `font_dirs` is the union of:
 *   - the skill-shipped fonts directory (Inter OFL, always present);
 *   - the principal's tier-2 upload directory (if it exists and is
 *     non-empty).
 *
 * Both directories are passed through `realpath()` so the typst
 * compiler (which does its own path normalisation) sees the same
 * paths regardless of whether the operator symlinked the storage
 * directory. This avoids the "font not found" class of bugs that
 * arise when `$paths->storage(...)` returns a logical path that
 * doesn't match what ext-typst resolves under the hood.
 *
 * `cache_size` is bounded: 64 MiB is enough for the boot of
 * Inter-Regular + Inter-Bold + a couple of compile cycles of a
 * typical document. Operators with much heavier workloads can
 * subclass and override, but a runaway cache is a common OOM
 * source in long-running workers.
 */
final class TypstWorldFactory
{
    private const CACHE_BYTES = 64 * 1024 * 1024;

    /** @var list<string> */
    private array $resolvedFontDirs = [];

    public function __construct(
        private readonly TypstResourcePaths $paths,
    ) {}

    /**
     * Build a fresh World + Compiler + Inspector triple sharing the
     * same backing state. The triple is cheap to construct — ext-typst
     * uses process-local Rust state — so each tool invocation gets a
     * fresh one rather than reusing across tool calls.
     *
     * @return array{world: World, compiler: Compiler, inspector: Inspector}
     */
    public function build(): array
    {
        $world = new World(
            template_dir: null,
            cache_size: self::CACHE_BYTES,
            embed_default_fonts: false,
            font_dirs: $this->fontDirs(),
            package_dir: null,
        );

        // `embed_default_fonts = false` keeps ext-typst from baking
        // Latin Modern into every output, so a render of a document
        // that does NOT reference Inter won't accidentally drag in
        // ~600 KB of fallback fonts. The operator-supplied Inter
        // fonts come from the merged `font_dirs` we just configured.

        $compiler  = new Compiler($world);
        $inspector = new Inspector($world);
        return [
            'world'     => $world,
            'compiler'  => $compiler,
            'inspector' => $inspector,
        ];
    }

    /**
     * @return list<string>
     */
    public function fontDirs(): array
    {
        if ($this->resolvedFontDirs !== []) {
            return $this->resolvedFontDirs;
        }
        $candidates = [
            $this->paths->skillFontDirectory(),
            $this->paths->principalFontDirectory(),
        ];
        $out = [];
        foreach ($candidates as $dir) {
            $real = realpath($dir);
            if ($real === false || !is_dir($real)) {
                continue;
            }
            // De-dupe in case the operator symlinked the storage root
            // to the plugin's skills directory (unusual but legal).
            if (!in_array($real, $out, true)) {
                $out[] = $real;
            }
        }
        if ($out === []) {
            throw new RuntimeException(sprintf(
                'TypstWorldFactory: no font directories are readable (skill="%s", principal="%s")',
                $this->paths->skillFontDirectory(),
                $this->paths->principalFontDirectory(),
            ));
        }
        $this->resolvedFontDirs = $out;
        return $out;
    }
}
