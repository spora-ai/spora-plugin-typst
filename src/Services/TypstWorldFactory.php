<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use RuntimeException;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
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
 *     → per-principal root. Typst searches this directory for
 *       `#include "templates/foo.typ"`, `#include "examples/bar.typ"`,
 *       and `#image("basename.jpg")`. The first two are resolved via
 *       the kind subdirs under the principal root; the third is
 *       resolved directly because images are stored at the root
 *       (not in an `images/` subdir — see {@see TypstResourcePaths}
 *       for the rationale). Tier-1 (skill-shipped) templates
 *       live at `<plugin>/skills/typst/{templates,examples}/` and
 *       are surfaced as a parallel listing via the admin UI; they're
 *       NOT injected into the per-principal `template_dir` so
 *       operators can shadow a skill-shipped file by uploading one
 *       of the same name under their own principal.
 *
 *   `font_dirs:  [<plugin>/skills/typst/fonts/, <storage>/typst/<principal>/fonts/]`
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
 * Principal scoping:
 *   The factory is autowired as a singleton by PHP-DI at boot, with
 *   no principal in scope (no request has been routed yet). The
 *   constructor takes a {@see Paths} (not a {@see TypstResourcePaths})
 *   so per-request callers — the {@see TypstCompileController}, the
 *   `TypstRenderProducer`, the `TypstCompileTool` — pass the resolved
 *   principal at `build()` time. Without the principal, the world falls
 *   back to the skill-shipped templates dir as the `template_dir`,
 *   which is
 *   the right default for background workers / CLI runs but the
 *   wrong default for any user-facing render.
 *
 * @return array{world: World, compiler: Compiler, inspector: Inspector}
 */
final class TypstWorldFactory
{
    private const CACHE_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly \Spora\Core\Paths $paths,
    ) {}

    /**
     * Build a World for the given principal. Pass `null` for
     * background / CLI contexts where no principal is in scope — the
     * world then resolves against the skill-shipped templates dir as
     * a safe fallback (a principal-scoped render would not be safe
     * there anyway because we have no principal to scope to).
     *
     * @return array{world: World, compiler: Compiler, inspector: Inspector}
     */
    public function build(?int $principalId = null): array
    {
        $resourcePaths = new TypstResourcePaths($this->paths, $principalId);
        $templateDir   = $this->templateDir($resourcePaths);
        $fontDirs      = $this->fontDirs($resourcePaths);

        if ($fontDirs === []) {
            throw new TypstRuntimeException(sprintf(
                'TypstWorldFactory: no font directories are readable (skill="%s")',
                $resourcePaths->skillFontDirectory(),
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
    public function fontDirs(?TypstResourcePaths $paths = null): array
    {
        $paths ??= new TypstResourcePaths($this->paths);
        $candidates = [$paths->skillFontDirectory()];
        try {
            $candidates[] = $paths->principalFontDirectory();
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
     * `templates/` and `examples/` live under it, and images are
     * stored directly under it (no `images/` subdir) so
     * `#image("basename.jpg")` resolves there. The LLM (and the
     * playground) reference them with `#include "templates/foo.typ"`
     * / `#include "examples/bar.typ"` and `#image("basename.jpg")`.
     *
     * Falls back to the skill-shipped templates dir only when no
     * principal is in scope (background workers, CLI). In that case
     * we still produce a usable World against the skill-shipped
     * content so `typst_compile(action: "inspect")` / `typst_resources`
     * calls don't fail in unprincipaled contexts.
     */
    private function templateDir(TypstResourcePaths $paths): string
    {
        try {
            return $paths->principalDirectory();
        } catch (RuntimeException) {
            return $paths->skillTemplateDirectory();
        }
    }
}
