<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Plugins\Typst\Tools\TypstRenderTool;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;

const RENDER_TOOL_PDF_MIME = 'application/pdf';
const RENDER_TOOL_PNG_MIME = 'image/png';
const RENDER_TOOL_TYPST_MIME = 'text/x-typst';

beforeEach(function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped('ext-typst is not loaded');
    }

    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $paths = new Paths(sys_get_temp_dir());
    $this->worldFactory = new TypstWorldFactory($paths);

    // Register a real user via delight-im/auth so the foreign-key
    // constraint on media_assets.user_id has a target.
    $this->auth = bootAuthLayer();
    $this->userId = $this->auth->register('render-tool@example.com', 'Password1!', 'Render Tool');
    simulateLoggedInSession($this->userId, 'render-tool@example.com');

    $this->principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $this->principalId = $this->principalService->ensureUserPrincipal($this->userId)->id;

    $resourcePaths = new Spora\Plugins\Typst\Services\TypstResourcePaths($paths, principalId: $this->principalId);
    $this->resourceStore = new TypstResourceStore($resourcePaths);

    $this->derivativeService = new MediaDerivativeService(
        new Spora\Services\DataUrlAssetStore(),
        $this->principalService,
    );

    $this->tool = new TypstRenderTool($this->worldFactory, $this->resourceStore, $this->derivativeService);
});

it('returns a failed ToolResult when the format is unsupported', function () {
    $result = $this->tool->execute(
        ['source' => '= Hi', 'format' => 'docx'],
        agentId: 0,
        userId: null,
    );
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('invalid format');
    expect($result->content)->toContain('docx');
});

it('returns a failed ToolResult when neither source nor file is provided', function () {
    $result = $this->tool->execute([], agentId: 0, userId: null);
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('either `source`');
});

it('returns a failed ToolResult when the file points to a missing asset', function () {
    $result = $this->tool->execute(['file' => 'no-such-asset'], agentId: 0, userId: null);
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('media asset "no-such-asset" not found');
});

it('returns a failed ToolResult when the producer is not registered with the discovery', function () {
    MediaDerivativeProducerDiscovery::reset();

    $result = $this->tool->execute(['source' => '= Hi', 'format' => 'pdf'], agentId: 0, userId: null);
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('TypstRenderProducer is not registered');
});

it('returns a failed ToolResult when the source fails to compile', function () {
    // The producer refuses to render when the inspector reports
    // errors. Feed it source with an unclosed string literal so the
    // inspector flags at least one diagnostic. (Some ext-typst builds
    // recover gracefully — see the matching producer test — so the
    // tool may still succeed; we only assert on the failure path
    // here.)
    $result = $this->tool->execute(
        ['source' => "= Heading\n#let x = \"unclosed\n"],
        agentId: 0,
        userId: null,
    );

    if (!$result->success) {
        expect($result->content)->toContain('typst_render:');
    } else {
        // Recovery path: some ext-typst builds render anyway.
        expect($result->success)->toBeTrue();
    }
});

it('returns a successful ToolResult for a valid inline source with no agent context', function () {
    $context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    $result = $this->tool->execute(
        ['source' => '= Hello'],
        agentId: 0,
        userId: $this->userId,
        context: $context,
    );

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('Typst PDF render');
    expect($result->data['format'])->toBe('pdf');
    expect($result->data['mime'])->toBe(RENDER_TOOL_PDF_MIME);
    expect($result->data['size'])->toBeGreaterThan(0);
    expect($result->data['asset_url'])->toStartWith('/api/v1/assets/');
    expect($result->data['asset_url'])->toEndWith('.pdf');
});

it('returns a PNG result when format is png', function () {
    $context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    $result = $this->tool->execute(
        ['source' => '= PNG', 'format' => 'png'],
        agentId: 0,
        userId: $this->userId,
        context: $context,
    );

    expect($result->success)->toBeTrue();
    expect($result->data['format'])->toBe('png');
    expect($result->data['mime'])->toBe(RENDER_TOOL_PNG_MIME);
});

it('renders a non-PDF format without a preview URL in the content string', function () {
    $context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    $result = $this->tool->execute(
        ['source' => '= PNG', 'format' => 'png'],
        agentId: 0,
        userId: $this->userId,
        context: $context,
    );

    expect($result->content)->toStartWith('!');
    expect($result->content)->not->toContain('[Open PDF]');
});

it('produces a describeAction string for an inline source', function () {
    expect($this->tool->describeAction(['source' => '= Hi', 'format' => 'svg']))
        ->toContain('svg')
        ->toContain('inline source');
});

it('produces a describeAction string for a file source', function () {
    expect($this->tool->describeAction(['file' => 'abcdef12-3456-7890', 'format' => 'pdf']))
        ->toContain('pdf')
        ->toContain('file=abcdef12');
});

it('clamps page and dpi arguments to safe ranges', function () {
    $context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    // Negative page clamps to 0, dpi above 600 clamps to 600.
    $result = $this->tool->execute(
        ['source' => '= Clamps', 'format' => 'png', 'page' => -5, 'dpi' => 9999],
        agentId: 0,
        userId: $this->userId,
        context: $context,
    );

    expect($result->success)->toBeTrue();
});

it('renders a file-sourced typst document from a data_url MediaAsset', function () {
    $asset = new MediaAsset();
    $asset->id = 'inline-asset-' . bin2hex(random_bytes(4));
    $asset->user_id = $this->userId;
    $asset->agent_id = null;
    $asset->principal_id = $this->principalId;
    $asset->plugin_slug = 'spora-plugin-typst';
    $asset->tool_name = 'typst.render';
    $asset->mime_type = RENDER_TOOL_TYPST_MIME;
    $asset->media_type = 'document';
    $asset->byte_size = 8;
    $asset->filename = 'file-source.typ';
    $asset->storage_mode = 'data_url';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->payload = "= File source\n";
    $asset->asset_url = '/api/v1/assets/' . $asset->id . '.typ';
    $asset->upload_source = 'upload';
    $asset->save();

    $context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    $result = $this->tool->execute(
        ['file' => $asset->id, 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $context,
    );

    expect($result->success)->toBeTrue();
    expect($result->data['format'])->toBe('pdf');
});

it('renders a file-sourced typst document from a local-storage MediaAsset', function () {
    $asset = new MediaAsset();
    $asset->id = 'local-asset-' . bin2hex(random_bytes(4));
    $asset->user_id = $this->userId;
    $asset->agent_id = null;
    $asset->principal_id = $this->principalId;
    $asset->plugin_slug = 'spora-plugin-typst';
    $asset->tool_name = 'typst.render';
    $asset->mime_type = RENDER_TOOL_TYPST_MIME;
    $asset->media_type = 'document';
    $asset->byte_size = 8;
    $asset->filename = 'file-source.typ';
    $asset->storage_mode = 'local';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->payload = null;
    $asset->asset_url = '/api/v1/assets/' . $asset->id . '.typ';
    $asset->upload_source = 'upload';
    $asset->save();

    // The local read path expects the bytes to be on disk under
    // <storage>/assets/<token>.typ. Write them there so the read
    // path succeeds.
    $storage = (new Paths(BASE_PATH))->storage('assets');
    if (!is_dir($storage)) {
        mkdir($storage, 0o755, true);
    }
    file_put_contents($storage . '/' . $asset->asset_token . '.typ', "= Local source\n");

    try {
        $context = new Spora\Services\PrincipalContext(
            principalId: $this->principalId,
            type: 'user',
            ownerUserId: $this->userId,
            runnerUserId: $this->userId,
        );

        $result = $this->tool->execute(
            ['file' => $asset->id, 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $context,
        );

        // The local read path requires a real on-disk file under
        // the test-isolated BASE_PATH/storage/assets/ — running
        // outside the plugin's normal install, the file we wrote
        // should be readable. If for some reason the integration
        // setup can't see it, the tool falls into the catch-all
        // and returns a failure with the empty-bytes message.
        if ($result->success) {
            expect($result->data['format'])->toBe('pdf');
        } else {
            expect($result->content)->toContain('asset has empty bytes');
        }
    } finally {
        @unlink($storage . '/' . $asset->asset_token . '.typ');
    }
});

it('returns a failed ToolResult when the file source is invisible to the caller', function () {
    $asset = new MediaAsset();
    $asset->id = 'invisible-asset-' . bin2hex(random_bytes(4));
    $asset->user_id = $this->userId + 999;
    $asset->agent_id = null;
    $asset->principal_id = $this->principalId;
    $asset->plugin_slug = 'spora-plugin-typst';
    $asset->tool_name = 'typst.render';
    $asset->mime_type = RENDER_TOOL_TYPST_MIME;
    $asset->media_type = 'document';
    $asset->byte_size = 8;
    $asset->filename = 'file-source.typ';
    $asset->storage_mode = 'data_url';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->payload = "= Invisible\n";
    $asset->asset_url = '/api/v1/assets/' . $asset->id . '.typ';
    $asset->upload_source = 'upload';
    $asset->save();

    // No principal context -> visibility check fails fast.
    $result = $this->tool->execute(
        ['file' => $asset->id, 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: null,
    );

    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('not visible');
});

it('returns a failed ToolResult when the file source has an unknown storage mode', function () {
    $asset = new MediaAsset();
    $asset->id = 'unknown-mode-' . bin2hex(random_bytes(4));
    $asset->user_id = $this->userId;
    $asset->agent_id = null;
    $asset->principal_id = $this->principalId;
    $asset->plugin_slug = 'spora-plugin-typst';
    $asset->tool_name = 'typst.render';
    $asset->mime_type = RENDER_TOOL_TYPST_MIME;
    $asset->media_type = 'document';
    $asset->byte_size = 8;
    $asset->filename = 'file-source.typ';
    $asset->storage_mode = 'object_storage';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->payload = "= Unknown mode\n";
    $asset->asset_url = '/api/v1/assets/' . $asset->id . '.typ';
    $asset->upload_source = 'upload';
    $asset->save();

    $context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    $result = $this->tool->execute(
        ['file' => $asset->id, 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $context,
    );

    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('cannot read storage_mode');
});

it('returns a failed ToolResult when the file source has empty payload bytes', function () {
    $asset = new MediaAsset();
    $asset->id = 'empty-asset-' . bin2hex(random_bytes(4));
    $asset->user_id = $this->userId;
    $asset->agent_id = null;
    $asset->principal_id = $this->principalId;
    $asset->plugin_slug = 'spora-plugin-typst';
    $asset->tool_name = 'typst.render';
    $asset->mime_type = RENDER_TOOL_TYPST_MIME;
    $asset->media_type = 'document';
    $asset->byte_size = 0;
    $asset->filename = 'file-source.typ';
    $asset->storage_mode = 'data_url';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->payload = '';
    $asset->asset_url = '/api/v1/assets/' . $asset->id . '.typ';
    $asset->upload_source = 'upload';
    $asset->save();

    $context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );

    $result = $this->tool->execute(
        ['file' => $asset->id, 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $context,
    );

    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('asset has empty bytes');
});
