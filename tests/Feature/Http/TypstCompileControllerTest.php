<?php

declare(strict_types=1);

use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
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
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= Hello']),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(401);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

it('POST /typst/compile rejects empty source with 422', function () {
    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '   ']),
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('POST /typst/compile rejects an unknown format with 422', function () {
    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= Hello', 'format' => 'docx']),
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
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= Hello']),
    );
    $this->auth->logOut();
    clearSession();

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(401);
});

it('POST /typst/compile persists a valid PDF render and returns the asset_url', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped('ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
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
        $this->markTestSkipped('ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
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
        $this->markTestSkipped('ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    // `= ` on its own with a dangling #include that the world factory
    // can't resolve forces the inspector to report an error.
    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
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
        $this->markTestSkipped('ext-typst is not loaded');
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
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
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
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{ this is not json',
    );

    $resp = $this->controller->compile($req);
    expect($resp->getStatusCode())->toBe(400);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
});
