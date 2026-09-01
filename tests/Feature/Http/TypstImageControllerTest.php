<?php

declare(strict_types=1);

use Spora\Plugins\Typst\Http\TypstImageController;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Services\DataUrlAssetStore;
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

    $this->store = new TypstImageStore(new DataUrlAssetStore());
    $this->controller = new TypstImageController(
        $this->auth,
        $this->principalService,
        $this->store,
    );
});

it('GET /typst/images returns an empty list when no images are uploaded', function () {
    $resp = $this->controller->index();
    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['images'])->toBe([]);
});

it('POST /typst/images uploads a base64-encoded PNG and returns the asset_url', function () {
    $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    // Re-encode the bytes to exercise the base64 auto-detection path
    // (decodeContent decodes once and stores the raw PNG bytes).
    $content = base64_encode(base64_decode($b64));

    $req = Request::create(
        '/api/v1/typst/images',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode([
            'filename' => 'logo.png',
            'mime'     => 'image/png',
            'content'  => $content,
        ]),
    );

    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['image']['mime_type'])->toBe('image/png');
    expect($body['data']['image']['filename'])->toBe('logo.png');
    expect($body['data']['image']['asset_url'])->toEndWith('.png');
    expect($body['data']['image']['byte_size'])->toBe(strlen(base64_decode($b64)));
});

it('POST /typst/images rejects an unsupported mime with 422', function () {
    $req = Request::create(
        '/api/v1/typst/images',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['mime' => 'image/gif', 'content' => 'abc']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(422);

    $body = json_decode((string) $resp->getContent(), true);
    expect($body['error']['code'])->toBe('UNSUPPORTED_MIME');
});

it('POST /typst/images rejects an empty content field', function () {
    $req = Request::create(
        '/api/v1/typst/images',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['mime' => 'image/png', 'content' => '']),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(422);
});

it('POST /typst/images accepts raw SVG markup as the content field', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';
    $req = Request::create(
        '/api/v1/typst/images',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode([
            'mime'    => 'image/svg+xml',
            'content' => $svg,
        ]),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['image']['asset_url'])->toEndWith('.svg');
});

it('GET /typst/images lists previously uploaded images', function () {
    // Reach into the store directly — controller-level upload is
    // already covered above; this test focuses on the listing wiring.
    $principalId = $this->principalService->ensureUserPrincipal(
        $this->auth->currentUserId(),
    )->id;
    $this->store->create('x', 'image/png', ['principal_id' => $principalId, 'filename' => 'one.png']);
    $this->store->create('x', 'image/png', ['principal_id' => $principalId, 'filename' => 'two.png']);

    $resp = $this->controller->index();
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['images'])->toHaveCount(2);
});

it('DELETE /typst/images/{id} soft-deletes the row', function () {
    $principalId = $this->principalService->ensureUserPrincipal(
        $this->auth->currentUserId(),
    )->id;
    $asset = $this->store->create('x', 'image/png', ['principal_id' => $principalId]);
    // Request::create doesn't auto-populate route attributes; the
    // host's router does. Mirror what the router would do.
    $req = Request::create('/api/v1/typst/images/' . $asset->id, 'DELETE');
    $req->attributes->set('id', $asset->id);

    expect($asset->principal_id)->toBe($principalId);
    expect($asset->plugin_slug)->toBe('spora-plugin-typst');

    $resp = $this->controller->destroy($req);
    expect($resp->getStatusCode())->toBe(204);
    expect(Spora\Models\MediaAsset::query()->find($asset->id))->toBeNull();
});

it('DELETE /typst/images/{id} returns 404 on a missing row', function () {
    $req = Request::create('/api/v1/typst/images/never-existed', 'DELETE');
    $req->attributes->set('id', 'never-existed');
    $resp = $this->controller->destroy($req);
    expect($resp->getStatusCode())->toBe(404);
});

it('POST /typst/images sanitises filenames with path separators', function () {
    $req = Request::create(
        '/api/v1/typst/images',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode([
            'filename' => '../../etc/passwd',
            'mime'     => 'image/png',
            'content'  => 'iVBORw0KGgo=',
        ]),
    );
    $resp = $this->controller->store($req);
    expect($resp->getStatusCode())->toBe(201);
    $body = json_decode((string) $resp->getContent(), true);
    expect($body['data']['image']['filename'])->not->toContain('/');
    expect($body['data']['image']['filename'])->not->toContain('..');
});
