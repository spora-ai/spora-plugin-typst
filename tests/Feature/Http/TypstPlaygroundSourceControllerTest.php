<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Http\TypstCompileController;
use Spora\Plugins\Typst\Http\TypstPlaygroundSourceController;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
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
    $userId = $this->auth->register('tester@example.com', 'Password1!', 'Tester');
    simulateLoggedInSession($userId, 'tester@example.com');

    $this->principalService = new PrincipalService(new PrincipalResolver());
    $this->derivativeService = new MediaDerivativeService(
        new DataUrlAssetStore(),
        $this->principalService,
    );

    $paths = new TypstResourcePaths(
        new Spora\Core\Paths(sys_get_temp_dir()),
        principalId: 1,
    );
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
    $row->mime_type     = 'text/x-typst';
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
        $this->markTestSkipped('ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= Hello', 'name' => 'letter.typ', 'format' => 'pdf']),
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
    expect($asset->mime_type)->toBe('text/x-typst');
    expect($asset->payload)->toBe('= Hello');
});

it('POST /typst/compile overwrites the existing source when called again with the same name', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped('ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $firstReq = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= first', 'name' => 'letter.typ', 'format' => 'pdf']),
    );
    $firstResp = $this->compileController->compile($firstReq);
    $firstBody = json_decode((string) $firstResp->getContent(), true);
    $firstId = $firstBody['data']['source_id'];

    $secondReq = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
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
        $this->markTestSkipped('ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= Hello', 'name' => 'invoice', 'format' => 'pdf']),
    );
    $resp = $this->compileController->compile($req);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['source_name'])->toBe('invoice.typ');
});

it('POST /typst/compile rejects names with path separators', function () {
    $req = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= Hello', 'name' => 'sub/dir.typ', 'format' => 'pdf']),
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

    $resp = $this->sourceController->index(Request::create('/api/v1/typst/sources', 'GET'));
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
    $otherUserId = $this->auth->register('other@example.com', 'Password1!', 'Other');
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
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['content' => '= After']),
    );
    $req->attributes->set('id', 'dddddddd-dddd-dddd-dddd-000000000001');
    $resp = $this->sourceController->update($req);
    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['byte_size'])->toBe(7);

    $reloaded = MediaAsset::query()->find($parent->id);
    expect($reloaded->payload)->toBe('= After');

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
        server: ['CONTENT_TYPE' => 'application/json'],
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

it('POST /typst/compile auto-appends .typ to bare names without re-creating the row', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped('ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    // Compile once with "draft" (no .typ) — should be stored as draft.typ.
    $firstReq = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= one', 'name' => 'draft', 'format' => 'pdf']),
    );
    $firstResp = $this->compileController->compile($firstReq);
    $firstBody = json_decode((string) $firstResp->getContent(), true);
    expect($firstBody['data']['source_name'])->toBe('draft.typ');
    $firstId = $firstBody['data']['source_id'];

    // Compile again with the same bare name — should still resolve to
    // draft.typ and overwrite the same row.
    $secondReq = Request::create(
        '/api/v1/typst/compile',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['source' => '= two', 'name' => 'draft', 'format' => 'pdf']),
    );
    $secondResp = $this->compileController->compile($secondReq);
    $secondBody = json_decode((string) $secondResp->getContent(), true);
    expect($secondBody['data']['source_id'])->toBe($firstId);
});
