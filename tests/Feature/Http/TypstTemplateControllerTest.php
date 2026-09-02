<?php

declare(strict_types=1);

const TEMPLATES_PATH = '/api/v1/typst/templates';
const JSON_MIME = 'application/json';

use Spora\Core\Paths;
use Spora\Plugins\Typst\Http\TypstTemplateController;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

beforeEach(function () {
    $this->auth = bootAuthLayer();
    $userId = $this->auth->register('tester@example.com', 'Password1!', 'Tester');
    simulateLoggedInSession($userId, 'tester@example.com');

    $this->principalService = new PrincipalService(new PrincipalResolver());

    $this->tempDir = sys_get_temp_dir() . '/typst-template-ctrl-test-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0o755, true);
    mkdir($this->tempDir . '/storage', 0o755, true);
    $this->paths = new Paths($this->tempDir);

    $this->resourcePaths = new TypstResourcePaths($this->paths, principalId: $this->principalService->ensureUserPrincipal($userId)->id);
    $this->resourceStore = new TypstResourceStore($this->resourcePaths);

    $this->controller = new TypstTemplateController(
        $this->auth,
        $this->principalService,
        $this->resourcePaths,
        $this->resourceStore,
    );
});

afterEach(function () {
    clearSession();
    foreach (['typst/fonts', 'typst/templates', 'typst/examples', 'typst/images', 'typst'] as $kindDir) {
        $dir = $this->paths->storage($kindDir);
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
    if (is_dir($this->tempDir)) {
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir);
    }
});

afterEach(function () {
    clearSession();
    if (is_dir($this->resourcePaths->principalDirectory())) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->resourcePaths->principalDirectory(), FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->resourcePaths->principalDirectory());
    }
});

it('GET /typst/templates lists the skill-shipped report.typ by default', function () {
    $resp = $this->controller->index(Request::create(TEMPLATES_PATH, 'GET'));
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    $names = array_column($body['data']['templates'], 'name');
    expect($names)->toContain('report.typ');
    foreach ($body['data']['templates'] as $row) {
        expect($row['kind'])->toBe('template');
    }
});

it('POST /typst/templates writes a template to <storage>/typst/<principal>/templates/', function () {
    $req = Request::create(
        TEMPLATES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => JSON_MIME],
        content: json_encode(['name' => 'letter.typ', 'content' => '= Letter']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['template']['name'])->toBe('letter.typ');
    expect($body['data']['template']['kind'])->toBe('template');
    expect($body['data']['template']['origin'])->toBe('principal');
});

it('GET /typst/templates/{name} returns the source bytes', function () {
    $this->resourceStore->write('template', 'letter.typ', '= Letter content');

    $req = Request::create('/api/v1/typst/templates/letter.typ', 'GET');
    $req->attributes->set('name', 'letter.typ');
    $resp = $this->controller->show($req);
    expect($resp->getStatusCode())->toBe(200);
    expect((string) $resp->getContent())->toBe('= Letter content');
    expect($resp->headers->get('Content-Type'))->toContain('text/plain');
})->skip(true, 'TODO: principal-scope coupling between seeded store and storeForCurrentUser()');

it('GET /typst/templates/{name} returns 404 for a missing template', function () {
    $req = Request::create('/api/v1/typst/templates/missing.typ', 'GET');
    $req->attributes->set('name', 'missing.typ');
    $resp = $this->controller->show($req);
    expect($resp->getStatusCode())->toBe(404);
});

it('DELETE /typst/templates/{name} removes the file', function () {
    $this->resourceStore->write('template', 'doomed.typ', 'doomed');
    expect(is_file($this->resourcePaths->principalTemplateDirectory() . '/doomed.typ'))->toBeTrue();

    $req = Request::create('/api/v1/typst/templates/doomed.typ', 'DELETE');
    $req->attributes->set('name', 'doomed.typ');
    $resp = $this->controller->destroy($req);
    expect($resp->getStatusCode())->toBe(204);
    expect(is_file($this->resourcePaths->principalTemplateDirectory() . '/doomed.typ'))->toBeFalse();
})->skip(true, 'TODO: principal-scope coupling');

it('POST /typst/templates validates the basename and content', function () {
    $req = Request::create(
        TEMPLATES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => JSON_MIME],
        content: json_encode(['name' => '', 'content' => '']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('GET /typst/templates respects ?principal_id for visible principals', function () {
    $req = Request::create('/api/v1/typst/templates?principal_id=99', 'GET');
    $resp = $this->controller->index($req);
    expect($resp->getStatusCode())->toBe(404);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
});
