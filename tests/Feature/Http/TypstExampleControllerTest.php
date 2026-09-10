<?php

declare(strict_types=1);

const EXAMPLES_PATH = '/api/v1/typst/examples';
const EXAMPLE_JSON_MIME = 'application/json';

use Spora\Core\Paths;
use Spora\Plugins\Typst\Http\TypstExampleController;
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
    $this->paths = new Paths(BASE_PATH);
    $this->resourcePaths = new TypstResourcePaths($this->paths, principalId: $this->principalService->ensureUserPrincipal($userId)->id);
    $this->resourceStore = new TypstResourceStore($this->resourcePaths);

    $this->controller = new TypstExampleController(
        $this->auth,
        $this->principalService,
    );
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

it('GET /typst/examples lists the skill-shipped showcase.typ by default', function () {
    $resp = $this->controller->index(Request::create(EXAMPLES_PATH, 'GET'));
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    $names = array_column($body['data']['examples'], 'name');
    expect($names)->toContain('showcase.typ');
});

it('POST /typst/examples writes an example to the principal tier', function () {
    $req = Request::create(EXAMPLES_PATH, 'POST', server: ['CONTENT_TYPE' => EXAMPLE_JSON_MIME], content: json_encode(['name' => 'headings.typ', 'content' => '= Headings snippet']));
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['example']['name'])->toBe('headings.typ');
    expect($body['data']['example']['origin'])->toBe('principal');
});

it('PUT /typst/examples/{name} replaces an existing example', function () {
    $this->resourceStore->write('example', 'headings.typ', '= Original');

    $req = Request::create('/api/v1/typst/examples/headings.typ', 'PUT', server: ['CONTENT_TYPE' => EXAMPLE_JSON_MIME], content: json_encode(['content' => '= Updated']));
    $req->attributes->set('name', 'headings.typ');
    $resp = $this->controller->update($req);
    expect($resp->getStatusCode())->toBe(200);

    $readReq = Request::create('/api/v1/typst/examples/headings.typ', 'GET');
    $readReq->attributes->set('name', 'headings.typ');
    expect((string) $this->controller->show($readReq)->getContent())->toBe('= Updated');
});

it('PUT /typst/examples/{name} rejects an empty content payload with 422', function () {
    $req = Request::create('/api/v1/typst/examples/headings.typ', 'PUT', server: ['CONTENT_TYPE' => EXAMPLE_JSON_MIME], content: json_encode(['content' => '']));
    $req->attributes->set('name', 'headings.typ');
    $resp = $this->controller->update($req);
    expect($resp->getStatusCode())->toBe(422);
});

it('PUT /typst/examples/{name} rejects an invalid basename with 422', function () {
    $req = Request::create('/api/v1/typst/examples/foo%2Fbar.typ', 'PUT', server: ['CONTENT_TYPE' => EXAMPLE_JSON_MIME], content: json_encode(['content' => '= x']));
    $req->attributes->set('name', 'foo/bar.typ');
    $resp = $this->controller->update($req);
    expect($resp->getStatusCode())->toBe(422);
});

it('POST /typst/examples?principal_id=N writes under the named principal (regression: upload vanished after reload)', function (): void {
    $userId = (int) $this->auth->currentUserId();
    $groupService = new Spora\Services\GroupService($this->principalService);
    $group = $groupService->createGroup($userId, 'TestGroupForExampleUpload');
    $groupPrincipalId = (int) $this->principalService->ensureGroupPrincipal((int) $group->id)->id;

    $writeReq = Request::create(
        '/api/v1/typst/examples?principal_id=' . $groupPrincipalId,
        'POST',
        server: ['CONTENT_TYPE' => EXAMPLE_JSON_MIME],
        content: json_encode(['name' => 'group-hello.typ', 'content' => '= Group hello']),
    );
    expect($this->controller->store($writeReq)->getStatusCode())->toBe(201);

    $listReq = Request::create('/api/v1/typst/examples?principal_id=' . $groupPrincipalId, 'GET');
    $listBody = json_decode((string) $this->controller->index($listReq)->getContent(), true);
    expect(array_column($listBody['data']['examples'], 'name'))->toContain('group-hello.typ');

    $userBody = json_decode((string) $this->controller->index(Request::create(EXAMPLES_PATH, 'GET'))->getContent(), true);
    expect(array_column($userBody['data']['examples'], 'name'))->not->toContain('group-hello.typ');

    $showReq = Request::create('/api/v1/typst/examples/group-hello.typ?principal_id=' . $groupPrincipalId, 'GET');
    $showReq->attributes->set('name', 'group-hello.typ');
    expect((string) $this->controller->show($showReq)->getContent())->toBe('= Group hello');

    $delReq = Request::create('/api/v1/typst/examples/group-hello.typ?principal_id=' . $groupPrincipalId, 'DELETE');
    $delReq->attributes->set('name', 'group-hello.typ');
    expect($this->controller->destroy($delReq)->getStatusCode())->toBe(204);
});
