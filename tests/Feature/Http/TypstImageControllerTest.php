<?php

declare(strict_types=1);

const IMAGES_PATH = '/api/v1/typst/images';
const JSON_MIME = 'application/json';
const PNG_MIME = 'image/png';

use Spora\Core\Paths;
use Spora\Plugins\Typst\Http\TypstImageController;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

beforeEach(function () {
    $this->auth = bootAuthLayer();
    // Register a real user via delight-im/auth so PrincipalService can
    // materialise the user-principal in tests. The `register` API
    // returns the freshly minted user_id.
    $userId = $this->auth->register('tester@example.com', 'Password1!', 'Tester');
    simulateLoggedInSession($userId, 'tester@example.com');

    $this->principalService = new PrincipalService(new PrincipalResolver());

    $this->tempDir = sys_get_temp_dir() . '/typst-image-ctrl-test-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0o755, true);
    mkdir($this->tempDir . '/storage', 0o755, true);
    $this->paths = new Paths($this->tempDir);

    $this->controller = new TypstImageController(
        $this->auth,
        $this->principalService,
        $this->paths,
    );
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->tempDir);
    }
});

it('GET /typst/images returns an empty list when no images are uploaded', function () {
    $resp = $this->controller->index(Request::create(IMAGES_PATH, 'GET'));
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['images'])->toBe([]);
});

it('POST /typst/images uploads a base64-encoded PNG and returns the URL', function () {
    $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    $content = base64_encode(base64_decode($b64));

    $req = Request::create(
        IMAGES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => JSON_MIME],
        content: json_encode([
            'filename' => 'logo.png',
            'mime'     => PNG_MIME,
            'content'  => $content,
        ]),
    );

    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['image']['mime'])->toBe(PNG_MIME);
    expect($body['data']['image']['name'])->toBe('logo.png');
    expect($body['data']['image']['url'])->toEndWith('logo.png');
    expect($body['data']['image']['size'])->toBe(strlen(base64_decode($b64)));
});

it('POST /typst/images rejects an unsupported mime with 422', function () {
    $req = Request::create(
        IMAGES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => JSON_MIME],
        content: json_encode(['mime' => 'image/gif', 'content' => 'abc']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(422);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('UNSUPPORTED_MIME');
});

it('POST /typst/images rejects an empty content field', function () {
    $req = Request::create(
        IMAGES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => JSON_MIME],
        content: json_encode(['mime' => PNG_MIME, 'content' => '']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(422);
});

it('POST /typst/images accepts raw SVG markup as the content field', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';
    $req = Request::create(
        IMAGES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => JSON_MIME],
        content: json_encode([
            'mime'    => 'image/svg+xml',
            'content' => $svg,
        ]),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['image']['name'])->toEndWith('.svg');
});

it('GET /typst/images/{name} streams the bytes with the right mime', function () {
    $principalId = $this->principalService->ensureUserPrincipal(
        $this->auth->currentUserId(),
    )->id;
    $store = new TypstImageStore(new TypstResourcePaths($this->paths, $principalId));
    $store->write('PNGBYTES', PNG_MIME, 'logo.png');

    $req = Request::create('/api/v1/typst/images/logo.png', 'GET');
    $req->attributes->set('name', 'logo.png');
    $resp = $this->controller->show($req);

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toBe(PNG_MIME);
    expect((string) $resp->getContent())->toBe('PNGBYTES');
});

it('GET /typst/images/{name} returns 404 for a missing image', function () {
    $req = Request::create('/api/v1/typst/images/missing.png', 'GET');
    $req->attributes->set('name', 'missing.png');
    $resp = $this->controller->show($req);
    expect($resp->getStatusCode())->toBe(404);
});

it('DELETE /typst/images/{name} removes the file from disk', function () {
    $principalId = $this->principalService->ensureUserPrincipal(
        $this->auth->currentUserId(),
    )->id;
    $store = new TypstImageStore(new TypstResourcePaths($this->paths, $principalId));
    $store->write('x', PNG_MIME, 'doomed.png');
    // Per-principal layout: images live directly under
    // <storage>/typst/<principal>/, not in an images/ subdir.
    expect(is_file($this->tempDir . '/storage/typst/' . $principalId . '/doomed.png'))->toBeTrue();

    $req = Request::create('/api/v1/typst/images/doomed.png', 'DELETE');
    $req->attributes->set('name', 'doomed.png');
    $resp = $this->controller->destroy($req);

    expect($resp->getStatusCode())->toBe(204);
    expect(is_file($this->tempDir . '/storage/typst/' . $principalId . '/doomed.png'))->toBeFalse();
});

it('DELETE /typst/images/{name} returns 404 for a missing image', function () {
    $req = Request::create('/api/v1/typst/images/missing.png', 'DELETE');
    $req->attributes->set('name', 'missing.png');
    $resp = $this->controller->destroy($req);
    expect($resp->getStatusCode())->toBe(404);
});

it('POST /typst/images sanitises filenames with path separators', function () {
    $req = Request::create(
        IMAGES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => JSON_MIME],
        content: json_encode([
            'filename' => '../../etc/passwd',
            'mime'     => PNG_MIME,
            'content'  => 'iVBORw0KGgo=',
        ]),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['image']['name'])->not->toContain('/');
    expect($body['data']['image']['name'])->not->toContain('..');
});

it('GET /typst/images with ?principal_id=99 returns 404 (principal not visible)', function () {
    $req = Request::create('/api/v1/typst/images?principal_id=99', 'GET');
    $resp = $this->controller->index($req);
    expect($resp->getStatusCode())->toBe(404);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
});
