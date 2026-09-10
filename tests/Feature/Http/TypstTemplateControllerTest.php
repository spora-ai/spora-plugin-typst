<?php

declare(strict_types=1);

const TEMPLATES_PATH = '/api/v1/typst/templates';
const TEMPLATE_JSON_MIME = 'application/json';

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

    // Mirror the controller's `paths()` resolution: it builds
    // `new Paths(BASE_PATH)` per request, so the seeded store must
    // resolve storage the same way or the controller and the
    // test fixture write to disjoint roots.
    $this->paths = new Paths(BASE_PATH);

    $this->resourcePaths = new TypstResourcePaths($this->paths, principalId: $this->principalService->ensureUserPrincipal($userId)->id);
    $this->resourceStore = new TypstResourceStore($this->resourcePaths);

    $this->controller = new TypstTemplateController(
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
        server: ['CONTENT_TYPE' => TEMPLATE_JSON_MIME],
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
});

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
});

it('POST /typst/templates validates the basename and content', function () {
    $req = Request::create(
        TEMPLATES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => TEMPLATE_JSON_MIME],
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

it('PUT /typst/templates/{name} replaces an existing template', function () {
    $this->resourceStore->write('template', 'letter.typ', '= Original');

    $req = Request::create('/api/v1/typst/templates/letter.typ', 'PUT', server: ['CONTENT_TYPE' => TEMPLATE_JSON_MIME], content: json_encode(['content' => '= Updated']));
    $req->attributes->set('name', 'letter.typ');
    $resp = $this->controller->update($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['template']['name'])->toBe('letter.typ');
    expect($body['data']['template']['size'])->toBe(strlen('= Updated'));

    // On-disk bytes are replaced — the next GET shows the new content.
    $readReq = Request::create('/api/v1/typst/templates/letter.typ', 'GET');
    $readReq->attributes->set('name', 'letter.typ');
    $readResp = $this->controller->show($readReq);
    expect((string) $readResp->getContent())->toBe('= Updated');
});

it('PUT /typst/templates/{name} rejects an empty content payload with 422', function () {
    $req = Request::create('/api/v1/typst/templates/letter.typ', 'PUT', server: ['CONTENT_TYPE' => TEMPLATE_JSON_MIME], content: json_encode(['content' => '']));
    $req->attributes->set('name', 'letter.typ');
    $resp = $this->controller->update($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('PUT /typst/templates/{name} allows shadowing a skill-shipped template (creates tier-2 file)', function () {
    // Shadowing is intentional — the operator can upload a custom
    // `report.typ` to override the skill-shipped built-in. PUT
    // against a tier-1 basename writes a tier-2 sibling that wins
    // on basename collision in the listing.
    $req = Request::create('/api/v1/typst/templates/report.typ', 'PUT', server: ['CONTENT_TYPE' => TEMPLATE_JSON_MIME], content: json_encode(['content' => '= Custom report']));
    $req->attributes->set('name', 'report.typ');
    $resp = $this->controller->update($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['template']['origin'])->toBe('principal');
    expect($body['data']['template']['size'])->toBe(strlen('= Custom report'));
});

it('POST /typst/templates?principal_id=N writes under the named principal (regression: upload vanished after reload)', function (): void {
    // Before the fix, store()/update()/destroy()/show() ignored
    // ?principal_id and always wrote to the caller's user-principal,
    // so uploads in another principal "vanished after reload" —
    // the next list call (scoped to the selected principal) didn't
    // see them. The fix routes show/store/update/destroy through
    // the same principal resolver as index().
    $userId = (int) $this->auth->currentUserId();
    $groupService = new Spora\Services\GroupService($this->principalService);
    $group = $groupService->createGroup($userId, 'TestGroupForTemplateUpload');
    $groupPrincipalId = (int) $this->principalService->ensureGroupPrincipal((int) $group->id)->id;

    // Write to the group principal.
    $writeReq = Request::create(
        '/api/v1/typst/templates?principal_id=' . $groupPrincipalId,
        'POST',
        server: ['CONTENT_TYPE' => TEMPLATE_JSON_MIME],
        content: json_encode(['name' => 'group-letter.typ', 'content' => '= Group letter']),
    );
    $writeResp = $this->controller->store($writeReq);
    expect($writeResp->getStatusCode())->toBe(201);

    // Listing under the group principal sees the new row.
    $listReq = Request::create('/api/v1/typst/templates?principal_id=' . $groupPrincipalId, 'GET');
    $listBody = json_decode((string) $this->controller->index($listReq)->getContent(), true);
    expect(array_column($listBody['data']['templates'], 'name'))->toContain('group-letter.typ');

    // The user's own principal does NOT see the row.
    $userReq = Request::create(TEMPLATES_PATH, 'GET');
    $userBody = json_decode((string) $this->controller->index($userReq)->getContent(), true);
    expect(array_column($userBody['data']['templates'], 'name'))->not->toContain('group-letter.typ');

    // Read it back via show() with the same principal_id.
    $showReq = Request::create('/api/v1/typst/templates/group-letter.typ?principal_id=' . $groupPrincipalId, 'GET');
    $showReq->attributes->set('name', 'group-letter.typ');
    $showResp = $this->controller->show($showReq);
    expect($showResp->getStatusCode())->toBe(200);
    expect((string) $showResp->getContent())->toBe('= Group letter');

    // Delete it under the group principal.
    $delReq = Request::create('/api/v1/typst/templates/group-letter.typ?principal_id=' . $groupPrincipalId, 'DELETE');
    $delReq->attributes->set('name', 'group-letter.typ');
    expect($this->controller->destroy($delReq)->getStatusCode())->toBe(204);

    // Listing under the group principal no longer sees it.
    $afterBody = json_decode((string) $this->controller->index($listReq)->getContent(), true);
    expect(array_column($afterBody['data']['templates'], 'name'))->not->toContain('group-letter.typ');
});

it('POST /typst/templates?principal_id=<invisible> returns 404 (probe protection)', function (): void {
    // A second user the caller can't see must not be writable.
    $otherUserId = $this->auth->register('outsider@example.com', 'Password1!', 'Outsider');
    $otherPrincipalId = (int) $this->principalService->ensureUserPrincipal($otherUserId)->id;

    $req = Request::create(
        '/api/v1/typst/templates?principal_id=' . $otherPrincipalId,
        'POST',
        server: ['CONTENT_TYPE' => TEMPLATE_JSON_MIME],
        content: json_encode(['name' => 'x.typ', 'content' => '= X']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(404);
    expect(json_decode((string) $resp->getContent(), true)['error']['code'])->toBe('NOT_FOUND');
});
