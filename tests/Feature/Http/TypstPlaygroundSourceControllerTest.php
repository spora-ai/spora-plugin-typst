<?php

declare(strict_types=1);

const PLAYGROUND_TYPST_MIME = 'text/x-typst';
const PLAYGROUND_JSON_MIME = 'application/json';
const PLAYGROUND_SOURCES_PATH = '/api/v1/typst/sources';
const PLAYGROUND_PASSWORD = 'Password1!';
const PLAYGROUND_AFTER_SOURCE = '= After';
const PLAYGROUND_PRINCIPAL_USER_ID = '44444444-4444-4444-4444-000000000001';
const PLAYGROUND_PRINCIPAL_GROUP_ID = '55555555-5555-5555-5555-000000000001';
const PLAYGROUND_SKIP_NO_EXT_TYPST = 'ext-typst is not loaded';
const PLAYGROUND_COMPILE_PATH = '/api/v1/typst/compile';
const PLAYGROUND_HELLO_SOURCE = '= Hello';

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Http\TypstPlaygroundSourceController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests for the playground-side "open / edit / delete" controller
 * and the compile endpoint's name-aware upsert behavior. Together
 * these let the operator UI treat playground files as first-class
 * rows in the media archive with a user-chosen filename instead of
 * a hard-coded `playground.typ` row created per-compile.
 */

beforeEach(function () {
    $this->auth = bootAuthLayer();
    $userId = $this->auth->register('tester@example.com', PLAYGROUND_PASSWORD, 'Tester');
    simulateLoggedInSession($userId, 'tester@example.com');

    $this->principalService = new PrincipalService(new PrincipalResolver());
    $this->derivativeService = new MediaDerivativeService(
        new DataUrlAssetStore(),
        $this->principalService,
    );

    $paths = new Spora\Core\Paths(sys_get_temp_dir());
    $this->worldFactory = new TypstWorldFactory($paths);

    $this->compileController = new TypstCompileController(
        $this->auth,
        $this->principalService,
        $this->derivativeService,
        $this->worldFactory,
    );

    $this->sourceController = new TypstPlaygroundSourceController(
        $this->auth,
        $this->principalService,
    );
});

afterEach(function () {
    clearSession();
    Capsule::table('media_derivatives')->delete();
    Capsule::table('media_assets')->where('plugin_slug', 'spora-plugin-typst')->delete();
});

/**
 * Build a playground-source MediaAsset directly (no compile path)
 * for tests that need a row in place before exercising the source
 * controller. Mirrors the columns {@see TypstCompileController}
 * fills when materialising the parent row.
 */
function seedPlaygroundSource(string $id, int $userId, int $principalId, string $filename, string $payload): MediaAsset
{
    $row = new MediaAsset();
    $row->id            = $id;
    $row->user_id       = $userId;
    $row->principal_id  = $principalId;
    $row->plugin_slug   = 'spora-plugin-typst';
    $row->tool_name     = 'typst.playground';
    $row->mime_type     = PLAYGROUND_TYPST_MIME;
    $row->media_type    = 'document';
    $row->byte_size     = strlen($payload);
    $row->filename      = $filename;
    $row->storage_mode  = 'data_url';
    $row->asset_token   = bin2hex(random_bytes(8));
    $row->upload_source = 'tool';
    $row->payload       = $payload;
    $row->asset_url     = '/api/v1/assets/' . $id . '.typ';
    $row->created_at    = Illuminate\Support\Carbon::now();
    $row->updated_at    = $row->created_at;
    $row->save();
    return $row;
}

it('POST /typst/compile uses a custom name and stores the parent row with it', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PLAYGROUND_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        PLAYGROUND_COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['source' => PLAYGROUND_HELLO_SOURCE, 'name' => 'letter.typ', 'format' => 'pdf']),
    );
    $resp = $this->compileController->compile($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['source_name'])->toBe('letter.typ');
    expect($body['data']['source_id'])->toBeString()->not->toBe('');

    $asset = MediaAsset::query()->find($body['data']['source_id']);
    expect($asset)->not->toBeNull();
    expect($asset->filename)->toBe('letter.typ');
    expect($asset->tool_name)->toBe('typst.playground');
    expect($asset->mime_type)->toBe(PLAYGROUND_TYPST_MIME);
    expect($asset->payload)->toBe('= Hello');
});

it('POST /typst/compile overwrites the existing source when called again with the same name', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PLAYGROUND_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $firstReq = Request::create(
        PLAYGROUND_COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['source' => '= first', 'name' => 'letter.typ', 'format' => 'pdf']),
    );
    $firstResp = $this->compileController->compile($firstReq);
    $firstBody = json_decode((string) $firstResp->getContent(), true);
    $firstId = $firstBody['data']['source_id'];

    $secondReq = Request::create(
        PLAYGROUND_COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['source' => '= second', 'name' => 'letter.typ', 'format' => 'pdf']),
    );
    $secondResp = $this->compileController->compile($secondReq);
    $secondBody = json_decode((string) $secondResp->getContent(), true);

    // Same parent row, not a new one.
    expect($secondBody['data']['source_id'])->toBe($firstId);

    $playgroundRows = MediaAsset::query()
        ->where('tool_name', 'typst.playground')
        ->where('filename', 'letter.typ')
        ->get();
    expect($playgroundRows)->toHaveCount(1);
    expect($playgroundRows->first()->payload)->toBe('= second');
});

it('POST /typst/compile appends .typ when the name lacks the suffix', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PLAYGROUND_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        PLAYGROUND_COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['source' => PLAYGROUND_HELLO_SOURCE, 'name' => 'invoice', 'format' => 'pdf']),
    );
    $resp = $this->compileController->compile($req);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['source_name'])->toBe('invoice.typ');
});

it('POST /typst/compile rejects names with path separators', function () {
    $req = Request::create(
        PLAYGROUND_COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['source' => PLAYGROUND_HELLO_SOURCE, 'name' => 'sub/dir.typ', 'format' => 'pdf']),
    );
    $resp = $this->compileController->compile($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('GET /typst/sources lists the user\'s playground source rows', function () {
    $principalId = (int) $this->principalService->ensureUserPrincipal(
        (int) $this->auth->currentUserId(),
    )->id;
    $userId = (int) $this->auth->currentUserId();

    seedPlaygroundSource('aaaaaaaa-aaaa-aaaa-aaaa-000000000001', $userId, $principalId, 'letter.typ', '= Hello');
    seedPlaygroundSource('aaaaaaaa-aaaa-aaaa-aaaa-000000000002', $userId, $principalId, 'invoice.typ', '= Invoice');

    $resp = $this->sourceController->index(Request::create(PLAYGROUND_SOURCES_PATH, 'GET'));
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['sources'])->toHaveCount(2);

    $names = array_column($body['data']['sources'], 'filename');
    expect($names)->toContain('letter.typ')
        ->and($names)->toContain('invoice.typ');
});

it('GET /typst/sources/{id} returns the source bytes', function () {
    $principalId = (int) $this->principalService->ensureUserPrincipal(
        (int) $this->auth->currentUserId(),
    )->id;
    $userId = (int) $this->auth->currentUserId();

    seedPlaygroundSource('bbbbbbbb-bbbb-bbbb-bbbb-000000000001', $userId, $principalId, 'letter.typ', '= Letter!');

    $req = Request::create('/api/v1/typst/sources/bbbbbbbb-bbbb-bbbb-bbbb-000000000001', 'GET');
    $req->attributes->set('id', 'bbbbbbbb-bbbb-bbbb-bbbb-000000000001');
    $resp = $this->sourceController->show($req);

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['content'])->toBe('= Letter!');
    expect($body['data']['filename'])->toBe('letter.typ');
});

it('GET /typst/sources/{id} returns 404 for an out-of-scope source', function () {
    // Create a row under a *different* principal — the caller's lookup
    // must miss and surface a 404, not 403, so the existence of
    // someone else's file isn't leaked.
    $otherUserId = $this->auth->register('other@example.com', PLAYGROUND_PASSWORD, 'Other');
    $otherPrincipalId = (int) $this->principalService->ensureUserPrincipal($otherUserId)->id;
    seedPlaygroundSource('cccccccc-cccc-cccc-cccc-000000000001', $otherUserId, $otherPrincipalId, 'private.typ', '= Hi');

    $req = Request::create('/api/v1/typst/sources/cccccccc-cccc-cccc-cccc-000000000001', 'GET');
    $req->attributes->set('id', 'cccccccc-cccc-cccc-cccc-000000000001');
    $resp = $this->sourceController->show($req);
    expect($resp->getStatusCode())->toBe(404);
});

it('PUT /typst/sources/{id} persists edits and strips stale derivatives', function () {
    $principalId = (int) $this->principalService->ensureUserPrincipal(
        (int) $this->auth->currentUserId(),
    )->id;
    $userId = (int) $this->auth->currentUserId();

    $parent = seedPlaygroundSource('dddddddd-dddd-dddd-dddd-000000000001', $userId, $principalId, 'letter.typ', '= Before');

    // Two derivative rows pointing at the parent (PDF + PNG previews).
    foreach (['pdf', 'png'] as $i => $format) {
        $deriv = new MediaAsset();
        $deriv->id = sprintf('eeeeeeee-eeee-eeee-eeee-%012d', $i + 1);
        $deriv->user_id = $userId;
        $deriv->principal_id = $principalId;
        $deriv->plugin_slug = 'spora-plugin-typst';
        $deriv->tool_name = 'typst.playground';
        $deriv->mime_type = $format === 'pdf' ? 'application/pdf' : 'image/png';
        $deriv->media_type = 'document';
        $deriv->byte_size = 100;
        $deriv->filename = "letter.$format";
        $deriv->storage_mode = 'data_url';
        $deriv->asset_token = bin2hex(random_bytes(8));
        $deriv->upload_source = 'tool';
        $deriv->payload = 'fake';
        $deriv->asset_url = '/api/v1/assets/eeeeeeee-eeee-eeee-eeee-' . sprintf('%012d', $i + 1) . '.' . $format;
        $deriv->created_at = Illuminate\Support\Carbon::now();
        $deriv->updated_at = $deriv->created_at;
        $deriv->save();

        Capsule::table('media_derivatives')->insert([
            'id'                 => $deriv->id,
            'parent_id'          => $parent->id,
            'derivative_id'      => $deriv->id,
            'format'             => $format,
            'producer_plugin'    => 'spora-plugin-typst',
            'producer_operation' => 'compile',
            'created_at'        => Illuminate\Support\Carbon::now(),
        ]);
    }

    $req = Request::create(
        '/api/v1/typst/sources/dddddddd-dddd-dddd-dddd-000000000001',
        'PUT',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['content' => PLAYGROUND_AFTER_SOURCE]),
    );
    $req->attributes->set('id', 'dddddddd-dddd-dddd-dddd-000000000001');
    $resp = $this->sourceController->update($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['byte_size'])->toBe(7);

    $reloaded = MediaAsset::query()->find($parent->id);
    expect($reloaded->payload)->toBe(PLAYGROUND_AFTER_SOURCE);

    // Derivatives gone.
    $remaining = Capsule::table('media_derivatives')
        ->where('parent_id', $parent->id)
        ->count();
    expect($remaining)->toBe(0);
    $remainingAssets = MediaAsset::query()
        ->whereIn('id', ['eeeeeeee-eeee-eeee-eeee-000000000001', 'eeeeeeee-eeee-eeee-eeee-000000000002'])
        ->count();
    expect($remainingAssets)->toBe(0);
});

it('PUT /typst/sources/{id} rejects a missing content field with 422', function () {
    $req = Request::create(
        '/api/v1/typst/sources/any-id',
        'PUT',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode([]),
    );
    $req->attributes->set('id', 'any-id');
    $resp = $this->sourceController->update($req);
    expect($resp->getStatusCode())->toBe(422);
});

it('DELETE /typst/sources/{id} removes the parent and its derivatives', function () {
    $principalId = (int) $this->principalService->ensureUserPrincipal(
        (int) $this->auth->currentUserId(),
    )->id;
    $userId = (int) $this->auth->currentUserId();

    $parent = seedPlaygroundSource('ffffffff-ffff-ffff-ffff-000000000001', $userId, $principalId, 'letter.typ', '= Hi');

    $req = Request::create('/api/v1/typst/sources/ffffffff-ffff-ffff-ffff-000000000001', 'DELETE');
    $req->attributes->set('id', 'ffffffff-ffff-ffff-ffff-000000000001');
    $resp = $this->sourceController->destroy($req);
    expect($resp->getStatusCode())->toBe(204);

    expect(MediaAsset::query()->find($parent->id))->toBeNull();
});

it('POST /typst/sources creates a fresh row without compiling', function () {
    $req = Request::create(
        PLAYGROUND_SOURCES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['filename' => 'fresh.typ', 'content' => '= Hello, world!']),
    );
    $resp = $this->sourceController->store($req);
    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['filename'])->toBe('fresh.typ');
    expect($body['data']['byte_size'])->toBe(15);
    expect($body['data']['id'])->toBeString()->not->toBe('');

    $row = MediaAsset::query()->find($body['data']['id']);
    expect($row)->not->toBeNull();
    expect($row->tool_name)->toBe('typst.playground');
    expect($row->mime_type)->toBe(PLAYGROUND_TYPST_MIME);
    expect($row->payload)->toBe('= Hello, world!');
});

it('POST /typst/sources auto-appends .typ when the filename lacks the suffix', function () {
    $req = Request::create(
        PLAYGROUND_SOURCES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['filename' => 'draft', 'content' => '= draft']),
    );
    $resp = $this->sourceController->store($req);
    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['filename'])->toBe('draft.typ');
});

it('POST /typst/sources rejects names with path separators', function () {
    $req = Request::create(
        PLAYGROUND_SOURCES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['filename' => 'sub/dir.typ', 'content' => '= x']),
    );
    $resp = $this->sourceController->store($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('POST /typst/sources rejects a missing content field with 422', function () {
    $req = Request::create(
        PLAYGROUND_SOURCES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['filename' => 'foo.typ']),
    );
    $resp = $this->sourceController->store($req);
    expect($resp->getStatusCode())->toBe(422);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

it('POST /typst/sources returns 409 when a row with the same filename already exists', function () {
    $principalId = (int) $this->principalService->ensureUserPrincipal(
        (int) $this->auth->currentUserId(),
    )->id;
    seedPlaygroundSource('11111111-1111-1111-1111-000000000001', (int) $this->auth->currentUserId(), $principalId, 'taken.typ', '= old');

    $req = Request::create(
        PLAYGROUND_SOURCES_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['filename' => 'taken.typ', 'content' => '= new']),
    );
    $resp = $this->sourceController->store($req);
    expect($resp->getStatusCode())->toBe(409);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('FILENAME_TAKEN');
});

it('POST /typst/compile auto-appends .typ to bare names without re-creating the row', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped(PLAYGROUND_SKIP_NO_EXT_TYPST);
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    // Compile once with "draft" (no .typ) — should be stored as draft.typ.
    $firstReq = Request::create(
        PLAYGROUND_COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['source' => '= one', 'name' => 'draft', 'format' => 'pdf']),
    );
    $firstResp = $this->compileController->compile($firstReq);
    $firstBody = json_decode((string) $firstResp->getContent(), true);
    expect($firstBody['data']['source_name'])->toBe('draft.typ');
    $firstId = $firstBody['data']['source_id'];

    // Compile again with the same bare name — should still resolve to
    // draft.typ and overwrite the same row.
    $secondReq = Request::create(
        PLAYGROUND_COMPILE_PATH,
        'POST',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['source' => '= two', 'name' => 'draft', 'format' => 'pdf']),
    );
    $secondResp = $this->compileController->compile($secondReq);
    $secondBody = json_decode((string) $secondResp->getContent(), true);
    expect($secondBody['data']['source_id'])->toBe($firstId);
});

it('GET /typst/sources?principal_id=N scopes the listing to that principal', function () {
    $userId = (int) $this->auth->currentUserId();
    $userPrincipalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;
    // No-op: visibility check happens against the resolved principal,
    // not the caller — list() doesn't gate on visibility.

    // Spin up a group the caller owns (and is auto-added as a
    // member of) and seed a row under the group's principal. The
    // caller's user-principal also has a row — the listing should
    // distinguish the two.
    $groupService = new Spora\Services\GroupService($this->principalService);
    $group = $groupService->createGroup($userId, 'TestGroupForSources');
    $groupPrincipalId = (int) $this->principalService->ensureGroupPrincipal((int) $group->id)->id;

    seedPlaygroundSource('11111111-1111-1111-1111-000000000001', $userId, $userPrincipalId, 'mine.typ', '= User row');
    seedPlaygroundSource('11111111-1111-1111-1111-000000000002', $userId, $groupPrincipalId, 'group.typ', '= Group row');

    // Default (no principal_id) → user-principal only.
    $defaultResp = $this->sourceController->index(Request::create(PLAYGROUND_SOURCES_PATH, 'GET'));
    $defaultBody = json_decode((string) $defaultResp->getContent(), true);
    $defaultNames = array_column($defaultBody['data']['sources'], 'filename');
    expect($defaultNames)->toContain('mine.typ')->not->toContain('group.typ');

    // ?principal_id=<group> → group rows only.
    $groupReq = Request::create('/api/v1/typst/sources?principal_id=' . $groupPrincipalId, 'GET');
    $groupResp = $this->sourceController->index($groupReq);
    $groupBody = json_decode((string) $groupResp->getContent(), true);
    $groupNames = array_column($groupBody['data']['sources'], 'filename');
    expect($groupNames)->toContain('group.typ')->not->toContain('mine.typ');
});

it('GET /typst/sources?principal_id=N returns 404 for an out-of-scope principal', function () {
    $otherUserId = $this->auth->register('outsider@example.com', PLAYGROUND_PASSWORD, 'Outsider');
    $otherPrincipalId = (int) $this->principalService->ensureUserPrincipal($otherUserId)->id;

    $req = Request::create('/api/v1/typst/sources?principal_id=' . $otherPrincipalId, 'GET');
    $resp = $this->sourceController->index($req);

    expect($resp->getStatusCode())->toBe(404);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
});

it('GET /typst/sources/{id}?principal_id=N reads the row under that principal', function () {
    $userId = (int) $this->auth->currentUserId();
    $userPrincipalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;
    $this->principalService->ensureUserPrincipal($userId); // make user principal visible

    $groupService = new Spora\Services\GroupService($this->principalService);
    $group = $groupService->createGroup($userId, 'TestGroupForShow');
    $groupPrincipalId = (int) $this->principalService->ensureGroupPrincipal((int) $group->id)->id;

    seedPlaygroundSource('22222222-2222-2222-2222-000000000001', $userId, $userPrincipalId, 'mine.typ', '= User');
    seedPlaygroundSource('22222222-2222-2222-2222-000000000002', $userId, $groupPrincipalId, 'group.typ', '= Group');

    // Default principal (user) → only the user row is reachable.
    $defaultReq = Request::create('/api/v1/typst/sources/22222222-2222-2222-2222-000000000001', 'GET');
    $defaultReq->attributes->set('id', '22222222-2222-2222-2222-000000000001');
    $defaultResp = $this->sourceController->show($defaultReq);
    expect($defaultResp->getStatusCode())->toBe(200);

    // ?principal_id=<group> → reaches the group row.
    $groupReq = Request::create(
        '/api/v1/typst/sources/22222222-2222-2222-2222-000000000002?principal_id=' . $groupPrincipalId,
        'GET',
    );
    $groupReq->attributes->set('id', '22222222-2222-2222-2222-000000000002');
    $groupResp = $this->sourceController->show($groupReq);
    expect($groupResp->getStatusCode())->toBe(200);
    $body = json_decode((string) $groupResp->getContent(), true);
    expect($body['data']['content'])->toBe('= Group');
});

it('GET /typst/sources/{id}?principal_id=N returns 404 when the row lives under a different principal', function () {
    $userId = (int) $this->auth->currentUserId();
    $userPrincipalId = (int) $this->principalService->ensureUserPrincipal($userId)->id;
    $this->principalService->ensureUserPrincipal($userId); // make user principal visible

    $groupService = new Spora\Services\GroupService($this->principalService);
    $group = $groupService->createGroup($userId, 'TestGroupForMismatch');
    $groupPrincipalId = (int) $this->principalService->ensureGroupPrincipal((int) $group->id)->id;

    // Row lives under the user-principal; ask for it under the group.
    seedPlaygroundSource('33333333-3333-3333-3333-000000000001', $userId, $userPrincipalId, 'mine.typ', '= User');

    $req = Request::create(
        '/api/v1/typst/sources/33333333-3333-3333-3333-000000000001?principal_id=' . $groupPrincipalId,
        'GET',
    );
    $req->attributes->set('id', '33333333-3333-3333-3333-000000000001');
    $resp = $this->sourceController->show($req);
    expect($resp->getStatusCode())->toBe(404);
});

it('PUT /typst/sources/{id}?principal_id=N persists edits under the right principal', function () {
    $userId = (int) $this->auth->currentUserId();
    // Materialise the user-principal so the visibility check sees it
    // (createGroup below adds the owner as a group member, so the
    // group-principal becomes visible too).
    $this->principalService->ensureUserPrincipal($userId);
    $groupService = new Spora\Services\GroupService($this->principalService);
    $group = $groupService->createGroup($userId, 'TestGroupForUpdate');
    $groupPrincipalId = (int) $this->principalService->ensureGroupPrincipal((int) $group->id)->id;

    seedPlaygroundSource(PLAYGROUND_PRINCIPAL_USER_ID, $userId, $groupPrincipalId, 'group.typ', '= Before');

    $req = Request::create(
        '/api/v1/typst/sources/44444444-4444-4444-4444-000000000001?principal_id=' . $groupPrincipalId,
        'PUT',
        server: ['CONTENT_TYPE' => PLAYGROUND_JSON_MIME],
        content: json_encode(['content' => PLAYGROUND_AFTER_SOURCE]),
    );
    $req->attributes->set('id', PLAYGROUND_PRINCIPAL_USER_ID);
    $resp = $this->sourceController->update($req);
    expect($resp->getStatusCode())->toBe(200);

    $reloaded = MediaAsset::query()->find(PLAYGROUND_PRINCIPAL_USER_ID);
    expect($reloaded->payload)->toBe(PLAYGROUND_AFTER_SOURCE);
    expect($reloaded->principal_id)->toBe($groupPrincipalId);
});

it('DELETE /typst/sources/{id}?principal_id=N removes the row scoped to that principal', function () {
    $userId = (int) $this->auth->currentUserId();
    $this->principalService->ensureUserPrincipal($userId);
    $groupService = new Spora\Services\GroupService($this->principalService);
    $group = $groupService->createGroup($userId, 'TestGroupForDelete');
    $groupPrincipalId = (int) $this->principalService->ensureGroupPrincipal((int) $group->id)->id;

    seedPlaygroundSource(PLAYGROUND_PRINCIPAL_GROUP_ID, $userId, $groupPrincipalId, 'group.typ', '= Hi');

    $req = Request::create(
        '/api/v1/typst/sources/55555555-5555-5555-5555-000000000001?principal_id=' . $groupPrincipalId,
        'DELETE',
    );
    $req->attributes->set('id', PLAYGROUND_PRINCIPAL_GROUP_ID);
    $resp = $this->sourceController->destroy($req);
    expect($resp->getStatusCode())->toBe(204);

    expect(MediaAsset::query()->find(PLAYGROUND_PRINCIPAL_GROUP_ID))->toBeNull();
});
