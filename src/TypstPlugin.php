<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst;

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Http\TypstExampleController;
use Spora\Plugins\Typst\Http\TypstFontController;
use Spora\Plugins\Typst\Http\TypstImageController;
use Spora\Plugins\Typst\Http\TypstPlaygroundSourceController;
use Spora\Plugins\Typst\Http\TypstTemplateController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Tools\TypstInspectTool;
use Spora\Plugins\Typst\Tools\TypstRenderTool;
use Spora\Plugins\Typst\Tools\TypstResourcesTool;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;

/**
 * Plugin entry point for `spora-plugin-typst`.
 *
 * Contributes one admin app (TypstApp), three LLM-callable tools
 * (`typst_render`, `typst_inspect`, `typst_resources`), nine REST
 * routes under `/api/v1/typst/{fonts,templates,examples,images,compile}*`,
 * the `TypstRenderProducer` (registered with the media-derivatives
 * discovery registry), DI bindings for the controllers and tools, the
 * `skills/typst/` directory (Inter OFL fonts + a starter invoice
 * template + a headings example), and the `typst-assistant` agent
 * template.
 *
 * Architectural invariants:
 *
 *   - **Inputs on the filesystem, outputs in the media archive.**
 *     Fonts, templates, examples, and images live as plain files in
 *     `<storage>/typst/<principal>/{fonts,templates,examples,images}/`.
 *     They do NOT pollute the media archive. Only the rendered Typst
 *     OUTPUTS (PDF/PNG/SVG) flow through `MediaDerivativeService` →
 *     `media_assets` → the chat's `MediaEmbed` markdown — mirroring
 *     how a chat tool's outputs naturally belong in the media
 *     library while its input material does not.
 *
 *   - **No dependency on `spora-plugin-media-archive`.** Inputs are
 *     served via the plugin's own `/api/v1/typst/{fonts,templates,
 *     examples,images}/*` routes; outputs go through core's
 *     `MediaDerivativeService::create()` and surface via core's
 *     `/api/v1/assets/<uuid>.<ext>`. No HTTP hop into Media
 *     Archive routes.
 *
 *   - **Typst world is principal-scoped.** The factory sets
 *     `template_dir` to `<storage>/typst/<principal>/` and
 *     `font_dirs` to `[<plugin>/skills/typst/fonts/, <storage>/typst/
 *     fonts/<principal>/]`. Skill-shipped templates live at the
 *     parallel `<plugin>/skills/typst/{templates,examples}/` paths
 *     and are surfaced in the admin UI as a separate listing; the
 *     per-principal `template_dir` deliberately does NOT include
 *     them, so the operator can shadow a skill-shipped file by
 *     uploading one of the same name under their principal.
 */
final class TypstPlugin extends AbstractPlugin
{
    private const SOURCES_ROUTE_PATTERN = '/api/v1/typst/sources/{id}';

    public function getName(): string
    {
        return (new TypstApp())->displayName();
    }

    /**
     * Wire DI bindings for the controllers + tools, and register the
     * `TypstRenderProducer` with the media-derivatives discovery
     * registry so the core `/api/v1/media/{id}/derivatives` endpoint
     * can dispatch to it.
     *
     * PHP-DI autowires the constructors; explicit bindings here are
     * only for the cases where the host `App` cannot resolve the
     * type (controllers with multi-dep ctor, the resource store
     * which depends on a principal-id parameter).
     */
    public function register(ContainerBuilder $builder): void
    {
        $builder->addDefinitions([
            TypstFontController::class             => \DI\autowire(),
            TypstTemplateController::class         => \DI\autowire(),
            TypstExampleController::class          => \DI\autowire(),
            TypstImageController::class            => \DI\autowire(),
            TypstCompileController::class          => \DI\autowire(),
            TypstPlaygroundSourceController::class => \DI\autowire(),
            TypstRenderTool::class                 => \DI\autowire(),
            TypstInspectTool::class                => \DI\autowire(),
            TypstResourcesTool::class              => \DI\autowire(),
        ]);

        // Idempotent — `MediaDerivativeProducerDiscovery::add()` no-ops
        // if the FQCN is already in the registry.
        MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);
    }

    /**
     * Register the nine `/api/v1/typst/*` routes behind Auth + CSRF.
     * Mirrors the spora-plugin-memories auth chain verbatim so the
     * admin UI's fetch() calls Just Work.
     */
    public function routes(MiddlewareRouteCollector $r): void
    {
        $auth = [AuthMiddleware::class, CsrfMiddleware::class];

        // Fonts
        $r->addRoute('GET', '/api/v1/typst/fonts', [TypstFontController::class, 'index'], $auth);
        $r->addRoute('GET', '/api/v1/typst/fonts/{name}', [TypstFontController::class, 'show'], $auth);
        $r->addRoute('POST', '/api/v1/typst/fonts', [TypstFontController::class, 'store'], $auth);
        $r->addRoute('DELETE', '/api/v1/typst/fonts/{name}', [TypstFontController::class, 'destroy'], $auth);

        // Templates (full document skeletons)
        $r->addRoute('GET', '/api/v1/typst/templates', [TypstTemplateController::class, 'index'], $auth);
        $r->addRoute('GET', '/api/v1/typst/templates/{name}', [TypstTemplateController::class, 'show'], $auth);
        $r->addRoute('POST', '/api/v1/typst/templates', [TypstTemplateController::class, 'store'], $auth);
        $r->addRoute('DELETE', '/api/v1/typst/templates/{name}', [TypstTemplateController::class, 'destroy'], $auth);

        // Examples (small pattern snippets — separate kind, separate URL prefix)
        $r->addRoute('GET', '/api/v1/typst/examples', [TypstExampleController::class, 'index'], $auth);
        $r->addRoute('GET', '/api/v1/typst/examples/{name}', [TypstExampleController::class, 'show'], $auth);
        $r->addRoute('POST', '/api/v1/typst/examples', [TypstExampleController::class, 'store'], $auth);
        $r->addRoute('DELETE', '/api/v1/typst/examples/{name}', [TypstExampleController::class, 'destroy'], $auth);

        // Images — the basename (not a row id) is the addressable key.
        $r->addRoute('GET', '/api/v1/typst/images', [TypstImageController::class, 'index'], $auth);
        $r->addRoute('GET', '/api/v1/typst/images/{name}', [TypstImageController::class, 'show'], $auth);
        $r->addRoute('POST', '/api/v1/typst/images', [TypstImageController::class, 'store'], $auth);
        $r->addRoute('DELETE', '/api/v1/typst/images/{name}', [TypstImageController::class, 'destroy'], $auth);

        // Playground — compile inline Typst source to PDF/PNG/SVG.
        $r->addRoute('POST', '/api/v1/typst/compile', [TypstCompileController::class, 'compile'], $auth);

        // Playground source files — list/open/create/save/delete the
        // .typ rows the compile endpoint materialises. The compile
        // path upserts the parent row by (principal_id, tool_name,
        // filename); this controller surfaces the rest of the
        // lifecycle (create without rendering, open, edit, delete)
        // for the operator UI.
        $r->addRoute('GET', '/api/v1/typst/sources', [TypstPlaygroundSourceController::class, 'index'], $auth);
        $r->addRoute('POST', '/api/v1/typst/sources', [TypstPlaygroundSourceController::class, 'store'], $auth);
        $r->addRoute('GET', self::SOURCES_ROUTE_PATTERN, [TypstPlaygroundSourceController::class, 'show'], $auth);
        $r->addRoute('PUT', self::SOURCES_ROUTE_PATTERN, [TypstPlaygroundSourceController::class, 'update'], $auth);
        $r->addRoute('DELETE', self::SOURCES_ROUTE_PATTERN, [TypstPlaygroundSourceController::class, 'destroy'], $auth);
    }

    /**
     * @return array<int, class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [
            TypstApp::class,
        ];
    }

    /**
     * @return array<int, class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array
    {
        return [
            TypstRenderTool::class,
            TypstInspectTool::class,
            TypstResourcesTool::class,
        ];
    }

    /**
     * @return string[]
     */
    public function skillPaths(): array
    {
        return [
            __DIR__ . '/../skills',
        ];
    }

    /**
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [
            __DIR__ . '/../agent-templates',
        ];
    }
}
