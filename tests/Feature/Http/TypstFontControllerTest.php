<?php

declare(strict_types=1);

const FONTS_PATH = '/api/v1/typst/fonts';
const FONT_JSON_MIME = 'application/json';
const FONT_OCTET_MIME = 'application/octet-stream';

use Spora\Core\Paths;
use Spora\Plugins\Typst\Http\TypstFontController;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The font controller resolves the on-disk storage location via
 * {@see TypstFontController::paths()},
 * which reads `BASE_PATH` (set by tests/bootstrap.php to the plugin
 * root). We mirror that layout in this test setup so controller calls
 * land in a place we control and can clean up afterwards.
 */
beforeEach(function () {
    $this->auth = bootAuthLayer();
    $userId = $this->auth->register('tester@example.com', 'Password1!', 'Tester');
    simulateLoggedInSession($userId, 'tester@example.com');

    $this->principalService = new PrincipalService(new PrincipalResolver());
    $this->principalId = $this->principalService->ensureUserPrincipal($userId)->id;

    $this->paths = new Paths(BASE_PATH);
    $this->resourcePaths = new TypstResourcePaths($this->paths, principalId: $this->principalId);
    $this->resourceStore = new TypstResourceStore($this->resourcePaths);

    $this->controller = new TypstFontController($this->auth, $this->principalService);
});

afterEach(function () {
    clearSession();
    if ($this->resourcePaths->principalDirectory()) {
        $dir = $this->resourcePaths->principalDirectory();
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dir);
        }
    }
});

it('GET /typst/fonts lists skill-shipped fonts by default', function () {
    $resp = $this->controller->index(Request::create(FONTS_PATH, 'GET'));
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    $names = array_column($body['data']['fonts'], 'name');
    expect($names)->toContain('Inter-Regular.otf');
});

it('GET /typst/fonts rejects an unauthenticated caller', function () {
    clearSession();
    $this->controller->index(Request::create(FONTS_PATH, 'GET'));
})->throws(Spora\Plugins\Typst\Exceptions\TypstRuntimeException::class, 'Authentication required');

it('GET /typst/fonts?principal_id=99 returns 404 when the principal is invisible', function () {
    $req = Request::create(FONTS_PATH . '?principal_id=99', 'GET');
    $resp = $this->controller->index($req);
    expect($resp->getStatusCode())->toBe(404);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
});

it('GET /typst/fonts/{name} returns 404 for a missing font', function () {
    $req = Request::create(FONTS_PATH . '/missing.otf', 'GET');
    $req->attributes->set('name', 'missing.otf');
    $resp = $this->controller->show($req);
    expect($resp->getStatusCode())->toBe(404);
});

it('POST /typst/fonts writes a base64-encoded font to the principal tier-2 directory', function () {
    $b64 = base64_encode('FAKE-FONT-BYTES');
    $req = Request::create(
        FONTS_PATH,
        'POST',
        server: ['CONTENT_TYPE' => FONT_JSON_MIME],
        content: json_encode(['name' => 'Acme-Regular.otf', 'content' => $b64]),
    );

    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['font']['name'])->toBe('Acme-Regular.otf');
    expect($body['data']['font']['kind'])->toBe('font');
    expect($body['data']['font']['size'])->toBe(strlen('FAKE-FONT-BYTES'));
    expect($body['data']['font']['origin'])->toBe('principal');
    expect(is_file($this->resourcePaths->principalFontDirectory() . '/Acme-Regular.otf'))->toBeTrue();
});

it('POST /typst/fonts treats raw short strings as literal bytes (no base64 decode)', function () {
    $req = Request::create(
        FONTS_PATH,
        'POST',
        server: ['CONTENT_TYPE' => FONT_JSON_MIME],
        content: json_encode(['name' => 'short.otf', 'content' => 'short literal text content']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['font']['size'])->toBe(strlen('short literal text content'));
});

it('POST /typst/fonts returns 422 when name is missing', function () {
    $req = Request::create(
        FONTS_PATH,
        'POST',
        server: ['CONTENT_TYPE' => FONT_JSON_MIME],
        content: json_encode(['content' => 'abc']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toContain('name');
});

it('POST /typst/fonts returns 422 when content is missing', function () {
    $req = Request::create(
        FONTS_PATH,
        'POST',
        server: ['CONTENT_TYPE' => FONT_JSON_MIME],
        content: json_encode(['name' => 'foo.otf']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
    expect($body['error']['message'])->toContain('content');
});

it('POST /typst/fonts returns 400 on malformed JSON body', function () {
    $req = Request::create(
        FONTS_PATH,
        'POST',
        server: ['CONTENT_TYPE' => FONT_JSON_MIME],
        content: 'this is not json',
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(400);
});

it('DELETE /typst/fonts/{name} removes the principal font', function () {
    $this->resourceStore->write('font', 'doomed.otf', 'doomed');
    expect(is_file($this->resourcePaths->principalFontDirectory() . '/doomed.otf'))->toBeTrue();

    $req = Request::create(FONTS_PATH . '/doomed.otf', 'DELETE');
    $req->attributes->set('name', 'doomed.otf');
    $resp = $this->controller->destroy($req);

    expect($resp->getStatusCode())->toBe(204);
    expect(is_file($this->resourcePaths->principalFontDirectory() . '/doomed.otf'))->toBeFalse();
});

it('DELETE /typst/fonts/{name} returns 422 for a missing font', function () {
    $req = Request::create(FONTS_PATH . '/missing.otf', 'DELETE');
    $req->attributes->set('name', 'missing.otf');
    $resp = $this->controller->destroy($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_DELETABLE');
});
