<?php

declare(strict_types=1);

use Spora\Core\MiddlewareRouteCollector;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Http\TypstExampleController;
use Spora\Plugins\Typst\Http\TypstFontController;
use Spora\Plugins\Typst\Http\TypstImageController;
use Spora\Plugins\Typst\Http\TypstPlaygroundSourceController;
use Spora\Plugins\Typst\Http\TypstTemplateController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Tools\TypstCompileTool;
use Spora\Plugins\Typst\Tools\TypstResourcesTool;
use Spora\Plugins\Typst\TypstApp;
use Spora\Plugins\Typst\TypstPlugin;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;

/**
 * Verifies the wiring of {@see TypstPlugin}'s `register()`,
 * `routes()`, `apps()`, `tools()`, `skillPaths()`, and
 * `agentTemplatePaths()` hooks.
 *
 * Coverage target: the route registration block (the biggest source
 * of uncovered statements — the controller-class FQCN strings, the
 * HTTP verb set, the auth middleware array, and the
 * `SOURCES_ROUTE_PATTERN` constant introduced when the playground
 * source routes were consolidated into a single URL pattern).
 */
beforeEach(function () {
    MediaDerivativeProducerDiscovery::reset();

    $this->plugin = new TypstPlugin();
});

it('reports the spora-plugin-typst name from the registered TypstApp', function () {
    expect($this->plugin->getName())->toBe((new TypstApp())->displayName());
});

it('registers TypstRenderProducer with the media-derivatives discovery at boot', function () {
    $builder = new DI\ContainerBuilder();
    $this->plugin->register($builder);

    expect(MediaDerivativeProducerDiscovery::all())->toContain(TypstRenderProducer::class);
});

it('registers every controller and tool FQCN as a PHP-DI autowire definition', function () {
    $builder = new DI\ContainerBuilder();
    $this->plugin->register($builder);

    $container = $builder->build();
    $expected = [
        TypstFontController::class,
        TypstTemplateController::class,
        TypstExampleController::class,
        TypstImageController::class,
        TypstCompileController::class,
        TypstPlaygroundSourceController::class,
        TypstCompileTool::class,
        TypstResourcesTool::class,
    ];
    foreach ($expected as $fqcn) {
        expect($container->has($fqcn))->toBeTrue($fqcn . ' is not registered');
    }
});

it('lists TypstApp as the only contributed admin app', function () {
    expect($this->plugin->apps())->toBe([TypstApp::class]);
});

it('lists the two Typst tools in the documented order', function () {
    expect($this->plugin->tools())->toBe([
        TypstCompileTool::class,
        TypstResourcesTool::class,
    ]);
});

it('exposes the plugin-local skills directory under skillPaths()', function () {
    $paths = $this->plugin->skillPaths();
    expect($paths)->toHaveCount(1);
    expect($paths[0])->toEndWith('skills');
});

it('exposes the plugin-local agent-templates directory under agentTemplatePaths()', function () {
    $paths = $this->plugin->agentTemplatePaths();
    expect($paths)->toHaveCount(1);
    expect($paths[0])->toEndWith('agent-templates');
});

it('registers the nine /api/v1/typst/* routes with auth + csrf protection', function () {
    // The plugin's routes() method accepts a MiddlewareRouteCollector
    // (final). Spin up a real collector with FastRoute's standard
    // parser/data-generator, register the plugin's routes on it,
    // and inspect the registered routes by reflecting on the
    // data-generator's protected `staticRoutes` table.
    $dataGenerator = new FastRoute\DataGenerator\GroupCountBased();
    $recorder = new MiddlewareRouteCollector(
        new FastRoute\RouteParser\Std(),
        $dataGenerator,
    );
    $this->plugin->routes($recorder);

    $reflection = new ReflectionObject($dataGenerator);
    $staticRoutes = $reflection->getProperty('staticRoutes')->getValue($dataGenerator);
    $variableMap = $reflection->getProperty('methodToRegexToRoutesMap')->getValue($dataGenerator);

    /** @var list<array{method: string, pattern: string, handler: array{0: class-string, 1: string}, middleware: list<class-string>}> $calls */
    $calls = [];

    // Static routes (no path variables). Structure:
    //   staticRoutes[$method][$pattern] = $handler
    foreach ($staticRoutes as $method => $patterns) {
        foreach ($patterns as $pattern => $handler) {
            $calls[] = [
                'method'     => (string) $method,
                'pattern'    => (string) $pattern,
                'handler'    => $handler['handler'] ?? null,
                'middleware' => $handler['middleware'] ?? [],
            ];
        }
    }

    // Variable routes ({name}, {id}). FastRoute encodes them as
    // `/api/v1/typst/fonts/([^/]+)`; replace each `([^/]+)` capture
    // group with the named variable in declaration order.
    foreach ($variableMap as $method => $regexMap) {
        foreach ($regexMap as $route) {
            $regex = (string) $route->regex;
            $variables = $route->variables;
            $pattern = $regex;
            foreach ($variables as $name) {
                $pattern = (string) preg_replace('!\(\[\^/\]\+\)!', '{' . $name . '}', $pattern, 1);
            }

            $calls[] = [
                'method'     => (string) $method,
                'pattern'    => $pattern,
                'handler'    => $route->handler['handler'] ?? null,
                'middleware' => $route->handler['middleware'] ?? [],
            ];
        }
    }

    // Auth middleware applied to every endpoint.
    $expectedAuth = [AuthMiddleware::class, CsrfMiddleware::class];
    foreach ($calls as $route) {
        expect($route['middleware'])->toBe($expectedAuth);
    }

    // Endpoint matrix.
    $byPattern = [];
    foreach ($calls as $route) {
        $byPattern[$route['pattern']][$route['method']] = $route;
    }

    // Fonts (4 endpoints, no PUT)
    expect($byPattern['/api/v1/typst/fonts']['GET']['handler'])->toBe([TypstFontController::class, 'index']);
    expect($byPattern['/api/v1/typst/fonts']['POST']['handler'])->toBe([TypstFontController::class, 'store']);
    expect($byPattern['/api/v1/typst/fonts/{name}']['GET']['handler'])->toBe([TypstFontController::class, 'show']);
    expect($byPattern['/api/v1/typst/fonts/{name}']['DELETE']['handler'])->toBe([TypstFontController::class, 'destroy']);

    // Templates (4 endpoints)
    expect($byPattern['/api/v1/typst/templates']['GET']['handler'])->toBe([TypstTemplateController::class, 'index']);
    expect($byPattern['/api/v1/typst/templates']['POST']['handler'])->toBe([TypstTemplateController::class, 'store']);
    expect($byPattern['/api/v1/typst/templates/{name}']['GET']['handler'])->toBe([TypstTemplateController::class, 'show']);
    expect($byPattern['/api/v1/typst/templates/{name}']['DELETE']['handler'])->toBe([TypstTemplateController::class, 'destroy']);

    // Examples (4 endpoints)
    expect($byPattern['/api/v1/typst/examples']['GET']['handler'])->toBe([TypstExampleController::class, 'index']);
    expect($byPattern['/api/v1/typst/examples']['POST']['handler'])->toBe([TypstExampleController::class, 'store']);
    expect($byPattern['/api/v1/typst/examples/{name}']['GET']['handler'])->toBe([TypstExampleController::class, 'show']);
    expect($byPattern['/api/v1/typst/examples/{name}']['DELETE']['handler'])->toBe([TypstExampleController::class, 'destroy']);

    // Images (4 endpoints)
    expect($byPattern['/api/v1/typst/images']['GET']['handler'])->toBe([TypstImageController::class, 'index']);
    expect($byPattern['/api/v1/typst/images']['POST']['handler'])->toBe([TypstImageController::class, 'store']);
    expect($byPattern['/api/v1/typst/images/{name}']['GET']['handler'])->toBe([TypstImageController::class, 'show']);
    expect($byPattern['/api/v1/typst/images/{name}']['DELETE']['handler'])->toBe([TypstImageController::class, 'destroy']);

    // Compile (1 endpoint, POST only)
    expect($byPattern['/api/v1/typst/compile']['POST']['handler'])->toBe([TypstCompileController::class, 'compile']);

    // Playground sources — the three verb-scoped routes share the
    // {id} URL pattern that was extracted into the SOURCES_ROUTE_PATTERN
    // constant. The test verifies the URL pattern is used verbatim
    // (no duplication between GET/PUT/DELETE) and each verb maps to
    // the matching controller method.
    expect($byPattern['/api/v1/typst/sources/{id}']['GET']['handler'])->toBe([TypstPlaygroundSourceController::class, 'show']);
    expect($byPattern['/api/v1/typst/sources/{id}']['PUT']['handler'])->toBe([TypstPlaygroundSourceController::class, 'update']);
    expect($byPattern['/api/v1/typst/sources/{id}']['DELETE']['handler'])->toBe([TypstPlaygroundSourceController::class, 'destroy']);
    expect($byPattern['/api/v1/typst/sources']['GET']['handler'])->toBe([TypstPlaygroundSourceController::class, 'index']);
    expect($byPattern['/api/v1/typst/sources']['POST']['handler'])->toBe([TypstPlaygroundSourceController::class, 'store']);
});
