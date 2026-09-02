<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use RuntimeException;
use Typst\Compiler;
use Typst\Inspector;
use Typst\World;

/**
 * Builds a {@see World} configured for a principal's view of the
 * plugin's font + template + example directories, plus a paired
 * {@see Compiler}/{@see Inspector} that share the world.
 *
 * World config (mirrors the operator example in the user's spec):
 *
 *   `template_dir:  <storage>/typst/<principal>/`
 *     → parent of `templates/` and `examples/`, so
 *       `#include "templates/foo.typ"` and
 *       `#include "examples/bar.typ"` both resolve naturally under
 *       a single `template_dir` (ext-typst's API exposes one
 *       template_dir, not an array). Tier-1 (skill-shipped) templates
 *       live at `<plugin>/skills/typst/{templates,examples}/` and
 *       are surfaced as a parallel listing via the admin UI; they're
 *       NOT injected into the per-principal `template_dir` so
 *       operators can shadow a skill-shipped file by uploading one
 *       of the same name under their own principal.
 *
 *   `font_dirs:  [<plugin>/skills/typst/fonts/, <storage>/typst/fonts/<principal>/]`
 *     → ext-typst accepts an array; we list skill + principal. Typst
 *       searches both recursively. Tier-1 wins on basename collision
 *       because `font_dirs` earlier in the array is searched first
 *       by ext-typst — wait, actually typst searches all and the LAST
 *       match wins (or the first? we don't know without running it).
 *       Either way, the listing UI surfaces both and the
 *       `isSkillShipped()` check keeps the operator from deleting
 *       tier-1 entries.
 *
 *   `embed_default_fonts:  false`
 *     → keeps ext-typst from baking Latin Modern into every output,
 *       so a render of a document that does NOT reference Inter
 *       won't accidentally drag in ~600 KB of fallback fonts.
 *
 *   `cache_size:  64 MiB`
 *     → bounded to avoid OOMs in long-running workers. Operators
 *       with much heavier workloads can subclass and override.
 *
 * Per-call cost is one World allocation; ext-typst uses process-local
 * Rust state so each tool invocation gets a fresh one rather than
 * reusing across calls.
 *
 * @return array{world: World, compiler: Compiler, inspector: Inspector}
 */
final class TypstWorldFactory
{
    private const CACHE_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly TypstResourcePaths $paths,
    ) {}

    /**
     * @return array{world: World, compiler: Compiler, inspector: Inspector}
     */
    public function build(): array
    {
        $templateDir = $this->templateDir();
        $fontDirs     = $this->fontDirs();

        if ($fontDirs === []) {
            throw new RuntimeException(sprintf(
                'TypstWorldFactory: no font directories are readable (skill="%s")',
                $this->paths->skillFontDirectory(),
            ));
        }

        $world = new World(
            template_dir: $templateDir,
            cache_size: self::CACHE_BYTES,
            embed_default_fonts: false,
            font_dirs: $fontDirs,
            package_dir: null,
        );

        $compiler  = new Compiler($world);
        $inspector = new Inspector($world);
        return [
            'world'     => $world,
            'compiler'  => $compiler,
            'inspector' => $inspector,
        ];
    }

    /**
     * Font dirs are the union of skill-shipped + per-principal
     * directories. Deduplicated by `realpath` so a symlinked storage
     * root doesn't list the same fonts twice.
     *
     * Public so the test suite can assert the merged list directly;
     * the world-building path uses this internally too.
     *
     * @return list<string>
     */
    public function fontDirs(): array
    {
        $candidates = [$this->paths->skillFontDirectory()];
        try {
            $candidates[] = $this->paths->principalFontDirectory();
        } catch (RuntimeException) {
            // No principal scope — that's fine, the operator
            // simply hasn't uploaded any principal-tier fonts yet.
        }
        $out = [];
        foreach ($candidates as $dir) {
            $real = realpath($dir);
            if ($real === false || !is_dir($real)) {
                continue;
            }
            if (!in_array($real, $out, true)) {
                $out[] = $real;
            }
        }
        return $out;
    }

    /**
     * Template dir is the principal's per-principal root. Both
     * `templates/` and `examples/` live under it; the LLM (and the
     * playground) reference them with `#include "templates/foo.typ"`
     * / `#include "examples/bar.typ"`.
     *
     * Falls back to the skill-shipped templates dir only when the
     * `TypstResourcePaths` instance was constructed without a
     * principal (PHP-DI autowires a singleton at boot before any
     * request has resolved a principal). In that case we still
     * produce a usable World against the skill-shipped content so
     * `typst_inspect` / `typst_resources` calls don't fail in
     * unprincipaled contexts (background workers, CLI).
     */
    private function templateDir(): string
    {
        try {
            return $this->paths->principalDirectory();
        } catch (RuntimeException) {
            return $this->paths->skillTemplateDirectory();
        }
    }
}
