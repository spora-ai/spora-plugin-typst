<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst;

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Typst\Http\TypstExampleController;
use Spora\Plugins\Typst\Http\TypstFontController;
use Spora\Plugins\Typst\Http\TypstImageController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Tools\TypstInspectTool;
use Spora\Plugins\Typst\Tools\TypstRenderTool;
use Spora\Plugins\Typst\Tools\TypstResourcesTool;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;

/**
 * Plugin entry point for `spora-plugin-typst`.
 *
 * Contributes one admin app (TypstApp), three LLM-callable tools
 * (`typst_render`, `typst_inspect`, `typst_resources`), six REST
 * routes under `/api/v1/typst/{fonts,examples}*`, the
 * `TypstRenderProducer` (registered with the media-derivatives
 * discovery registry), DI bindings for the controllers and tools,
 * the `skills/typst/` directory (Inter OFL fonts + a starter
 * invoice example), and the `typst-assistant` agent template.
 *
 * Architectural invariants:
 *
 *   - The plugin does **not** depend on `spora-plugin-media-archive`.
 *     `typst_render` calls `MediaDerivativeService::create()` directly
 *     via PHP — no HTTP hop into Media Archive routes — and the
 *     derivatives surface in chat via `MediaEmbed` referencing core's
 *     `/api/v1/assets/<uuid>.<ext>` route.
 *
 *   - The plugin is discoverable as a derivative producer by core's
 *     `/api/v1/media/{id}/derivatives` endpoint without any further
 *     wiring: the discovery registry picks up `TypstRenderProducer`
 *     on the plugin's first `register()` hook.
 */
final class TypstPlugin extends AbstractPlugin
{
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
            TypstFontController::class    => \DI\autowire(),
            TypstExampleController::class => \DI\autowire(),
            TypstImageController::class   => \DI\autowire(),
            TypstRenderTool::class        => \DI\autowire(),
            TypstInspectTool::class       => \DI\autowire(),
            TypstResourcesTool::class     => \DI\autowire(),
        ]);

        // Idempotent — `MediaDerivativeProducerDiscovery::add()` no-ops
        // if the FQCN is already in the registry.
        MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);
    }

    /**
     * Register the six `/api/v1/typst/*` routes behind Auth + CSRF.
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

        // Examples
        $r->addRoute('GET', '/api/v1/typst/examples', [TypstExampleController::class, 'index'], $auth);
        $r->addRoute('GET', '/api/v1/typst/examples/{name}', [TypstExampleController::class, 'show'], $auth);
        $r->addRoute('POST', '/api/v1/typst/examples', [TypstExampleController::class, 'store'], $auth);
        $r->addRoute('DELETE', '/api/v1/typst/examples/{name}', [TypstExampleController::class, 'destroy'], $auth);

        // Images — the row's `id` (not basename) is the addressable key.
        $r->addRoute('GET', '/api/v1/typst/images', [TypstImageController::class, 'index'], $auth);
        $r->addRoute('POST', '/api/v1/typst/images', [TypstImageController::class, 'store'], $auth);
        $r->addRoute('DELETE', '/api/v1/typst/images/{id}', [TypstImageController::class, 'destroy'], $auth);
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
