<?php

declare(strict_types=1);

const COMPILE_PATH = '/api/v1/typst/compile';
const COMPILE_JSON_MIME = 'application/json';
const SKIP_NO_EXT_TYPST = 'ext-typst is not loaded';
const HELLO_SOURCE = '= Hello';

use Spora\Auth\AuthService;
use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

beforeEach(function () {
    $this->auth = bootAuthLayer();
    $userId = $this->auth->register('tester@example.com', 'Password1!', 'Tester');
    simulateLoggedInSession($userId, 'tester@example.com');

    $this->principalService = new PrincipalService(new PrincipalResolver());
    $this->derivativeService = new MediaDerivativeService(
        new DataUrlAssetStore(),
        $this->principalService,
    );

    // The world factory is real; the controller reaches into it to
    // build producers via MediaDerivativeProducerDiscovery. Tests
    // that don't actually compile skip the ext-typst gate below.
    $paths = new Spora\Core\Paths(sys_get_temp_dir());
    $this->worldFactory = new TypstWorldFactory($paths);

    $this->controller = new TypstCompileController(
        $this->auth,
        $this->principalService,
        $this->derivativeService,
        $this->worldFactory,
    );
});

it('POST /typst/compile rejects unauthenticated callers with 401', function () {
    clearSession();
    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => HELLO_SOURCE]),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(401);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

it('POST /typst/compile rejects empty source with 422', function () {
    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => '   ']),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('POST /typst/compile rejects an unknown format with 422', function () {
    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => HELLO_SOURCE, 'format' => 'docx']),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toContain('docx');
});

it('POST /typst/compile returns 401 when the session is wiped between requests', function () {
    // Mirror a CSRF mismatch: the user is "logged in" by delight-im
    // cookies but their session superglobal is gone. Belt-and-braces
    // for the auth-chain failure mode.
    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => HELLO_SOURCE]),
    );
    $this->auth->logOut();
    clearSession();

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(401);
});

it('POST /typst/compile persists a valid PDF render and returns the asset_url', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => "= Hello, Typst!\n", 'format' => 'pdf']),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['format'])->toBe('pdf');
    expect($body['data']['mime'])->toBe('application/pdf');
    expect($body['data']['asset_url'])->toEndWith('.pdf');
    expect($body['data']['derivative_id'])->toBeString()->not->toBe('');
    // PDF renders pair with a first-page PNG preview.
    expect($body['data']['preview_url'] ?? '')->toEndWith('.png');
});

it('POST /typst/compile persists a PNG render and returns width + height', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => "= Hello, Typst!\n", 'format' => 'png', 'dpi' => 144]),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['format'])->toBe('png');
    expect($body['data']['mime'])->toBe('image/png');
    expect($body['data']['width'])->toBeGreaterThan(0);
    expect($body['data']['height'])->toBeGreaterThan(0);
    // PNG renders don't include a preview_url — the PNG IS the preview.
    expect(isset($body['data']['preview_url']))->toBeFalse();
});

it('POST /typst/compile surfaces ext-typst diagnostics on a compile failure', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    // `= ` on its own with a dangling #include that the world factory
    // can't resolve forces the inspector to report an error.
    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode([
            'source' => "#include \"does-not-exist.typ\"\n",
            'format' => 'pdf',
        ]),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('COMPILATION_FAILED');
    expect($body['error']['diagnostics'] ?? [])->not->toBe([]);
});

it('POST /typst/compile strips absolute filesystem paths from diagnostic messages', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    // The image-not-found path on the real build leaks a
    // "(searched at /Users/.../skills/typst/templates/foo.jpg)"
    // segment, which exposes the operator's filesystem layout
    // and the plugin's installed location to anyone who can
    // see the playground's error toast. The controller must
    // strip that.
    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode([
            'source' => "#image(\"missing.jpg\")\n",
            'format' => 'pdf',
        ]),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('COMPILATION_FAILED');

    $json = (string) $resp->getContent();
    // POSIX absolute paths should not appear in the public error.
    expect($json)->not->toMatch('#/(Users|home|opt|var|tmp|private)/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+){1,}#');
    // The "(searched at ...)" clause is replaced with a
    // generic "(file not found)".
    expect($json)->not->toContain('searched at');
});

it('POST /typst/compile rejects invalid JSON with 400', function () {
    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: '{ this is not json',
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
});

/**
 * Build a {@see TypstCompileController} that uses a Mockery producer
 * instead of `MediaDerivativeProducerDiscovery`. Lets the controller's
 * happy paths run on CI hosts without ext-typst — the controller talks
 * to the producer through `MediaDerivativeProducerInterface` so a
 * Mockery mock is enough.
 */
function buildStubProducerController(
    AuthService $auth,
    PrincipalService $principals,
    MediaDerivativeService $derivativeService,
    TypstWorldFactory $worldFactory,
    MediaDerivativeProducerInterface $producer,
): TypstCompileController {
    return new TypstCompileController(
        $auth,
        $principals,
        $derivativeService,
        $worldFactory,
        producerFactory: static fn() => $producer,
    );
}

it('POST /typst/compile persists a PDF render via the stub-producer factory', function (): void {
    $producer = Mockery::mock(MediaDerivativeProducerInterface::class);
    $producer->shouldReceive('pluginSlug')->andReturn('spora-plugin-typst');
    $producer->shouldReceive('operationName')->andReturn('typst.playground');
    $producer->shouldReceive('produce')->andReturnUsing(
        function (Spora\Models\MediaAsset $source, string $format, array $options = []) {
            return match ($format) {
                'pdf' => new Spora\Services\MediaArchive\DerivativeOutput('%PDF-1.4 fake', 'application/pdf', width: 612, height: 792),
                'png' => new Spora\Services\MediaArchive\DerivativeOutput("\x89PNG\r\n\x1a\nfake", 'image/png', width: 612, height: 792),
                default => throw new RuntimeException("unsupported format: {$format}"),
            };
        },
    );

    $controller = buildStubProducerController(
        $this->auth,
        $this->principalService,
        $this->derivativeService,
        $this->worldFactory,
        $producer,
    );

    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => "= Hello\n", 'format' => 'pdf']),
    );

    $resp = $controller->compile($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['format'])->toBe('pdf');
    expect($body['data']['mime'])->toBe('application/pdf');
    expect($body['data']['preview_url'] ?? '')->toEndWith('.png');
});

it('POST /typst/compile persists a PNG render via the stub-producer factory (no preview)', function (): void {
    $producer = Mockery::mock(MediaDerivativeProducerInterface::class);
    $producer->shouldReceive('pluginSlug')->andReturn('spora-plugin-typst');
    $producer->shouldReceive('operationName')->andReturn('typst.playground');
    $producer->shouldReceive('produce')->andReturnUsing(
        fn(Spora\Models\MediaAsset $source, string $format, array $options = []) =>
            new Spora\Services\MediaArchive\DerivativeOutput("\x89PNG\r\n\x1a\nfake", 'image/png', width: 100, height: 100),
    );

    $controller = buildStubProducerController(
        $this->auth,
        $this->principalService,
        $this->derivativeService,
        $this->worldFactory,
        $producer,
    );

    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => "= Hi\n", 'format' => 'png']),
    );

    $resp = $controller->compile($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['format'])->toBe('png');
    expect($body['data']['width'])->toBe(100);
    expect(isset($body['data']['preview_url']))->toBeFalse();
});

it('POST /typst/compile surfaces a compile failure via the stub-producer factory', function (): void {
    $producer = Mockery::mock(MediaDerivativeProducerInterface::class);
    $producer->shouldReceive('pluginSlug')->andReturn('spora-plugin-typst');
    $producer->shouldReceive('operationName')->andReturn('typst.playground');
    $producer->shouldReceive('produce')->andThrow(
        new Spora\Plugins\Typst\Exceptions\TypstCompilationException('compile failed', []),
    );

    $controller = buildStubProducerController(
        $this->auth,
        $this->principalService,
        $this->derivativeService,
        $this->worldFactory,
        $producer,
    );

    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => "= Hi\n", 'format' => 'pdf']),
    );

    $resp = $controller->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('COMPILATION_FAILED');
});

it('POST /typst/compile returns 503 PRODUCER_UNAVAILABLE when the factory returns null', function (): void {
    $controller = new TypstCompileController(
        $this->auth,
        $this->principalService,
        $this->derivativeService,
        $this->worldFactory,
        producerFactory: static fn() => null,
    );

    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => "= Hi\n"]),
    );

    $resp = $controller->compile($req);
    expect($resp->getStatusCode())->toBe(503);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('PRODUCER_UNAVAILABLE');
});

it('POST /typst/compile returns 422 PERSISTENCE_FAILED when the derivative service throws', function (): void {
    // `MediaDerivativeService` is `final`, so we exercise the same
    // code path by overflowing the DataUrlAssetStore ceiling — the
    // service's create() wraps the asset-store failure in the same
    // exception type the controller's `safePersistDerivative` catches.
    $producer = Mockery::mock(MediaDerivativeProducerInterface::class);
    $producer->shouldReceive('pluginSlug')->andReturn('spora-plugin-typst');
    $producer->shouldReceive('operationName')->andReturn('typst.playground');
    $producer->shouldReceive('produce')->andReturn(
        new Spora\Services\MediaArchive\DerivativeOutput(
            str_repeat('x', 51 * 1024 * 1024),
            'application/pdf',
        ),
    );

    $controller = buildStubProducerController(
        $this->auth,
        $this->principalService,
        $this->derivativeService,
        $this->worldFactory,
        $producer,
    );

    $req = Request::create(
        COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => COMPILE_JSON_MIME],
        content: json_encode(['source' => "= Hi\n", 'format' => 'pdf']),
    );

    $resp = $controller->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('PERSISTENCE_FAILED');
    expect($body['error']['message'])->toContain('failed to persist derivative');
});
