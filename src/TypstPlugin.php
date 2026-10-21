<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst;

use Closure;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Http\TypstExampleController;
use Spora\Plugins\Typst\Http\TypstFontController;
use Spora\Plugins\Typst\Http\TypstImageController;
use Spora\Plugins\Typst\Http\TypstPlaygroundSourceController;
use Spora\Plugins\Typst\Http\TypstPreviewController;
use Spora\Plugins\Typst\Http\TypstTemplateController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstImageImporter;
use Spora\Plugins\Typst\Tools\TypstCompileTool;
use Spora\Plugins\Typst\Tools\TypstResourcesTool;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point for `spora-plugin-typst`.
 *
 * Architectural invariants:
 *
 *   - **Inputs on the filesystem, outputs in the media archive.** Inputs
 *     are plain files under `<storage>/typst/<principal>/`; images sit at
 *     the principal root so `#image("basename.jpg")` resolves against the
 *     same `template_dir`. Only rendered output — PDF/PNG/SVG — reaches
 *     `MediaDerivativeService`.
 *
 *   - **No dependency on `spora-plugin-media-archive`.** Inputs are served
 *     by the plugin's own `/api/v1/typst/*` routes; outputs surface via
 *     core's `/api/v1/assets/<uuid>.<ext>`.
 *
 *   - **Typst world is principal-scoped.** Its `template_dir` deliberately
 *     excludes the bundled `skills/typst/{templates,examples}/`, so an
 *     operator can shadow a bundled file by uploading one of the same name.
 */
final class TypstPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    private const AUTH                  = [AuthMiddleware::class, CsrfMiddleware::class];
    private const SOURCES_ROUTE_PATTERN = '/api/v1/typst/sources/{id}';
    private const TEMPLATES_ROUTE_PATTERN = '/api/v1/typst/templates/{name}';
    private const EXAMPLES_ROUTE_PATTERN = '/api/v1/typst/examples/{name}';

    /**
     * The two spora-core lifecycle events that replace the deprecated
     * `register()` / `routes()` hooks: the container event fires once per
     * process, the routes event per request.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Load-bearing, not decorative: core's `MediaAllowedTypesService` unions
     * every producer's `supportedSourceFormats()` into the upload allowlist
     * and `isAllowed()` matches by exact string, so dropping the
     * registration makes `.typ` uploads 415.
     *
     * Discovery runs on every boot by design — the registry is an
     * in-process static that resets between tests, and `add()` no-ops when
     * the FQCN is already registered.
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
            TypstImageImporter::class              => \DI\autowire()
                // Closure seam — keeps the plugin decoupled from the
                // host's `final` MediaAssetReader.
                ->constructorParameter('mediaAssetReader', \DI\factory(static function (MediaAssetReader $reader): Closure {
                    return static fn(string $id, ?int $ownerUserId): ?array => $reader->readAsset($id, $ownerUserId);
                })),
        ]);

        MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);
    }

    /**
     * The plugin's `/api/v1/typst/*` routes, behind Auth + CSRF.
     */
    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $r = $event->routes();

        $r->addRoute('GET', '/api/v1/typst/fonts', [TypstFontController::class, 'index'], self::AUTH);
        $r->addRoute('GET', '/api/v1/typst/fonts/{name}', [TypstFontController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/fonts', [TypstFontController::class, 'store'], self::AUTH);
        $r->addRoute('DELETE', '/api/v1/typst/fonts/{name}', [TypstFontController::class, 'destroy'], self::AUTH);

        $r->addRoute('GET', '/api/v1/typst/templates', [TypstTemplateController::class, 'index'], self::AUTH);
        $r->addRoute('GET', self::TEMPLATES_ROUTE_PATTERN, [TypstTemplateController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/templates', [TypstTemplateController::class, 'store'], self::AUTH);
        $r->addRoute('PUT', self::TEMPLATES_ROUTE_PATTERN, [TypstTemplateController::class, 'update'], self::AUTH);
        $r->addRoute('DELETE', self::TEMPLATES_ROUTE_PATTERN, [TypstTemplateController::class, 'destroy'], self::AUTH);

        $r->addRoute('GET', '/api/v1/typst/examples', [TypstExampleController::class, 'index'], self::AUTH);
        $r->addRoute('GET', self::EXAMPLES_ROUTE_PATTERN, [TypstExampleController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/examples', [TypstExampleController::class, 'store'], self::AUTH);
        $r->addRoute('PUT', self::EXAMPLES_ROUTE_PATTERN, [TypstExampleController::class, 'update'], self::AUTH);
        $r->addRoute('DELETE', self::EXAMPLES_ROUTE_PATTERN, [TypstExampleController::class, 'destroy'], self::AUTH);

        // Images are addressed by basename; the sources below by row id.
        $r->addRoute('GET', '/api/v1/typst/images', [TypstImageController::class, 'index'], self::AUTH);
        $r->addRoute('GET', '/api/v1/typst/images/{name}', [TypstImageController::class, 'show'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/images', [TypstImageController::class, 'store'], self::AUTH);
        $r->addRoute('DELETE', '/api/v1/typst/images/{name}', [TypstImageController::class, 'destroy'], self::AUTH);

        // /compile persists a parent row and its derivative; /preview
        // returns the bytes inline and writes nothing.
        $r->addRoute('POST', '/api/v1/typst/compile', [TypstCompileController::class, 'compile'], self::AUTH);
        $r->addRoute('POST', '/api/v1/typst/preview', [TypstPreviewController::class, 'preview'], self::AUTH);

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

    /**
     * This plugin's catalogue directory: `locales/<lang>.json`, holding the
     * plugin's operator copy. The gate that keeps it in step with the source
     * is `tests/Unit/LocaleCatalogTest.php`.
     *
     * Needs a core that reads `translationsPaths()` — 0.31.0 and later; older
     * cores never call the hook, so the copy stays English.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
