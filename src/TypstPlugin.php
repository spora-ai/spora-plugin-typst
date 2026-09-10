<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst;

use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Typst\Converters\TypstSourcePassthroughConverter;
use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Http\TypstExampleController;
use Spora\Plugins\Typst\Http\TypstFontController;
use Spora\Plugins\Typst\Http\TypstImageController;
use Spora\Plugins\Typst\Http\TypstPlaygroundSourceController;
use Spora\Plugins\Typst\Http\TypstPreviewController;
use Spora\Plugins\Typst\Http\TypstTemplateController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Tools\TypstCompileTool;
use Spora\Plugins\Typst\Tools\TypstResourcesTool;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point for `spora-plugin-typst`.
 *
 * Contributes one admin app (TypstApp), two LLM-callable tools
 * (`typst_compile`, `typst_resources`), the REST routes under
 * `/api/v1/typst/{fonts,templates,examples,images,compile,sources}*`,
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
final class TypstPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    private const AUTH                  = [AuthMiddleware::class, CsrfMiddleware::class];
    private const SOURCES_ROUTE_PATTERN = '/api/v1/typst/sources/{id}';
    private const TEMPLATES_ROUTE_PATTERN = '/api/v1/typst/templates/{name}';
    private const EXAMPLES_ROUTE_PATTERN = '/api/v1/typst/examples/{name}';

    /**
     * Subscribes to the two spora-core lifecycle events that replace
     * the deprecated `register()` / `routes()` hooks. The container
     * event fires once per process (DI bindings + idempotent
     * media-archive discovery re-registration); the routes event
     * fires per request.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Wire DI bindings for the controllers + tools, and (idempotently)
     * register the `TypstRenderProducer` and
     * `TypstSourcePassthroughConverter` with the media discovery
     * registries.
     *
     * Discovery calls run on every boot by design — the registries are
     * in-process statics that reset between tests, and the discovery
     * classes no-op when the FQCN is already registered, so repeated
     * registration is harmless.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions([
            TypstFontController::class             => \DI\autowire(),
            TypstTemplateController::class         => \DI\autowire(),
            TypstExampleController::class          => \DI\autowire(),
            TypstImageController::class            => \DI\autowire(),
            TypstCompileController::class          => \DI\autowire(),
            TypstPreviewController::class          => \DI\autowire(),
            TypstPlaygroundSourceController::class => \DI\autowire(),
            TypstCompileTool::class                => \DI\autowire(),
            TypstResourcesTool::class              => \DI\autowire(),
        ]);

        MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);
        MediaConverterDiscovery::add(TypstSourcePassthroughConverter::class);
    }

    /**
     * Register the 25 `/api/v1/typst/*` routes behind Auth + CSRF.
     * Mirrors the spora-plugin-memories auth chain verbatim so the
     * admin UI's fetch() calls Just Work.
     */
    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $r = $event->routes();

        // Fonts
        $r->addRoute('GET', '/api/v1/typst/fonts', [TypstFontController::class, 'index'], self::AUTH);
        $r->addRoute('GET', '/api/v1/typst/fonts/{name}', [TypstFontController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/fonts', [TypstFontController::class, 'store'], self::AUTH);
        $r->addRoute('DELETE', '/api/v1/typst/fonts/{name}', [TypstFontController::class, 'destroy'], self::AUTH);

        // Templates (full document skeletons)
        $r->addRoute('GET', '/api/v1/typst/templates', [TypstTemplateController::class, 'index'], self::AUTH);
        $r->addRoute('GET', self::TEMPLATES_ROUTE_PATTERN, [TypstTemplateController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/templates', [TypstTemplateController::class, 'store'], self::AUTH);
        $r->addRoute('PUT', self::TEMPLATES_ROUTE_PATTERN, [TypstTemplateController::class, 'update'], self::AUTH);
        $r->addRoute('DELETE', self::TEMPLATES_ROUTE_PATTERN, [TypstTemplateController::class, 'destroy'], self::AUTH);

        // Examples (small pattern snippets — separate kind, separate URL prefix)
        $r->addRoute('GET', '/api/v1/typst/examples', [TypstExampleController::class, 'index'], self::AUTH);
        $r->addRoute('GET', self::EXAMPLES_ROUTE_PATTERN, [TypstExampleController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/examples', [TypstExampleController::class, 'store'], self::AUTH);
        $r->addRoute('PUT', self::EXAMPLES_ROUTE_PATTERN, [TypstExampleController::class, 'update'], self::AUTH);
        $r->addRoute('DELETE', self::EXAMPLES_ROUTE_PATTERN, [TypstExampleController::class, 'destroy'], self::AUTH);

        // Images — the basename (not a row id) is the addressable key.
        $r->addRoute('GET', '/api/v1/typst/images', [TypstImageController::class, 'index'], self::AUTH);
        $r->addRoute('GET', '/api/v1/typst/images/{name}', [TypstImageController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/images', [TypstImageController::class, 'store'], self::AUTH);
        $r->addRoute('DELETE', '/api/v1/typst/images/{name}', [TypstImageController::class, 'destroy'], self::AUTH);

        // Editor — compile inline Typst source to PDF/PNG/SVG.
        // /compile persists a media_assets + media_derivatives row;
        // /preview returns the bytes inline and writes nothing. The
        // operator-facing Editor tab defaults to /preview so each
        // render doesn't add a parent row to the media archive; the
        // LLM tool's typst_compile still uses /compile.
        $r->addRoute('POST', '/api/v1/typst/compile', [TypstCompileController::class, 'compile'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/preview', [TypstPreviewController::class, 'preview'], self::AUTH);

        // Playground source files — list/open/create/save/delete the
        // .typ rows the compile endpoint materialises. The compile
        // path upserts the parent row by (principal_id, tool_name,
        // filename); this controller surfaces the rest of the
        // lifecycle (create without rendering, open, edit, delete)
        // for the operator UI.
        $r->addRoute('GET', '/api/v1/typst/sources', [TypstPlaygroundSourceController::class, 'index'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/sources', [TypstPlaygroundSourceController::class, 'store'], self::AUTH);
        $r->addRoute('GET', self::SOURCES_ROUTE_PATTERN, [TypstPlaygroundSourceController::class, 'show'], self::AUTH);
        $r->addRoute('PUT', self::SOURCES_ROUTE_PATTERN, [TypstPlaygroundSourceController::class, 'update'], self::AUTH);
        $r->addRoute('DELETE', self::SOURCES_ROUTE_PATTERN, [TypstPlaygroundSourceController::class, 'destroy'], self::AUTH);
    }

    public function getName(): string
    {
        return (new TypstApp())->displayName();
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
            TypstCompileTool::class,
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
