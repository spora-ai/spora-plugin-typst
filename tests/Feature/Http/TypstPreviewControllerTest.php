<?php

declare(strict_types=1);

const PREVIEW_PATH = '/api/v1/typst/preview';
const PREVIEW_JSON_MIME = 'application/json';
const PREVIEW_SKIP_NO_EXT_TYPST = 'ext-typst is not loaded';

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\Typst\Http\TypstPreviewController;
use Spora\Plugins\Typst\Producers\TypstPreviewProducerInterface;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

beforeEach(function () {
    $this->auth = bootAuthLayer();
    $userId = $this->auth->register('tester@example.com', 'Password1!', 'Tester');
    simulateLoggedInSession($userId, 'tester@example.com');

    $this->principalService = new PrincipalService(new PrincipalResolver());
    $paths = new Spora\Core\Paths(sys_get_temp_dir());
    $this->worldFactory = new TypstWorldFactory($paths);

    $this->controller = new TypstPreviewController(
        $this->auth,
        $this->principalService,
        $this->worldFactory,
    );

    // Snapshot the media_assets row count so each test can assert
    // /preview is side-effect free. The PreviewController must
    // never INSERT into media_assets or media_derivatives.
    $this->initialAssetCount = (int) Capsule::table('media_assets')->count();
});

afterEach(function () {
    $this->auth->logOut();
    clearSession();
});

it('POST /typst/preview rejects unauthenticated callers with 401', function () {
    $this->auth->logOut();
    clearSession();

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => '= Hi']));
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(401);
});

it('POST /typst/preview rejects invalid JSON with 400', function () {
    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: '{ not json');
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(400);
    expect(json_decode((string) $resp->getContent(), true)['error']['code'])->toBe('INVALID_JSON');
});

it('POST /typst/preview rejects an empty source with 422', function () {
    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => '   ']));
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode((string) $resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

it('POST /typst/preview rejects an unknown format with 422', function () {
    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => '= Hi', 'format' => 'gif']));
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(422);
    expect(json_decode((string) $resp->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

it('POST /typst/preview returns base64 PDF bytes when ext-typst is loaded', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PREVIEW_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => "= Hello, Typst!\n", 'format' => 'pdf']));
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['format'])->toBe('pdf');
    expect($body['data']['mime'])->toBe('application/pdf');
    expect($body['data']['bytes'])->toBeString()->not->toBe('');
    expect(base64_decode($body['data']['bytes'], true))->toStartWith('%PDF-');
});

it('POST /typst/preview returns base64 PNG bytes with width + height when ext-typst is loaded', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PREVIEW_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => "= Hi\n", 'format' => 'png', 'dpi' => 144]));
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['format'])->toBe('png');
    expect($body['data']['mime'])->toBe('image/png');
    expect($body['data']['width'])->toBeGreaterThan(0);
    expect($body['data']['height'])->toBeGreaterThan(0);
});

it('POST /typst/preview returns 422 with structured diagnostics on a compile failure', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PREVIEW_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    // Missing #include — the inspector reports an error before the
    // compile ever runs.
    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode([
        'source' => "#include \"does-not-exist.typ\"\n",
        'format' => 'pdf',
    ]));
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('COMPILATION_FAILED');
    expect($body['error']['diagnostics'] ?? [])->not->toBe([]);
});

it('POST /typst/preview does NOT persist a media_assets row', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PREVIEW_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => "= Hi\n", 'format' => 'pdf']));
    $this->controller->preview($req);

    $after = (int) Capsule::table('media_assets')->count();
    expect($after)->toBe($this->initialAssetCount);
});

it('POST /typst/preview does NOT persist a media_derivatives row', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PREVIEW_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $initialDerivatives = (int) Capsule::table('media_derivatives')->count();

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => "= Hi\n", 'format' => 'pdf']));
    $this->controller->preview($req);

    $after = (int) Capsule::table('media_derivatives')->count();
    expect($after)->toBe($initialDerivatives);
});

it('POST /typst/preview via the stub-producer factory returns the producer bytes inline', function (): void {
    $producer = Mockery::mock(TypstPreviewProducerInterface::class);
    $producer->shouldReceive('produceFromString')
        ->andReturn(new DerivativeOutput('PDFBYTES', 'application/pdf'));

    $controller = new TypstPreviewController(
        $this->auth,
        $this->principalService,
        $this->worldFactory,
        producerFactory: static fn(): TypstPreviewProducerInterface => $producer,
    );

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => '= Hi', 'format' => 'pdf']));
    $resp = $controller->preview($req);
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['bytes'])->toBe(base64_encode('PDFBYTES'));
    expect($body['data']['format'])->toBe('pdf');
    expect($body['data']['mime'])->toBe('application/pdf');
});

it('POST /typst/preview surfaces the source_name back to the caller', function (): void {
    $producer = Mockery::mock(TypstPreviewProducerInterface::class);
    $producer->shouldReceive('produceFromString')
        ->andReturn(new DerivativeOutput('SVGBYTES', 'image/svg+xml'));

    $controller = new TypstPreviewController(
        $this->auth,
        $this->principalService,
        $this->worldFactory,
        producerFactory: static fn(): TypstPreviewProducerInterface => $producer,
    );

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => '#set page(width: 100pt)\n= Hi', 'name' => 'card.typ', 'format' => 'svg']));
    $resp = $controller->preview($req);
    expect($resp->getStatusCode())->toBe(200);
    expect(json_decode((string) $resp->getContent(), true)['data']['source_name'])->toBe('card.typ');
});

it('POST /typst/preview returns 503 when no producer is registered', function () {
    MediaDerivativeProducerDiscovery::reset();

    $req = Request::create(PREVIEW_PATH, 'POST', server: ['CONTENT_TYPE' => PREVIEW_JSON_MIME], content: json_encode(['source' => '= Hi']));
    $resp = $this->controller->preview($req);
    expect($resp->getStatusCode())->toBe(503);
    expect(json_decode((string) $resp->getContent(), true)['error']['code'])->toBe('PRODUCER_UNAVAILABLE');
});
