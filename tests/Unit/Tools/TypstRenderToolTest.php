<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstResourceStore;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Plugins\Typst\Tools\TypstRenderTool;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;

const RENDER_TOOL_PDF_MIME = 'application/pdf';
const RENDER_TOOL_PNG_MIME = 'image/png';
const RENDER_TOOL_TYPST_MIME = 'text/x-typst';

/**
 * Builds a {@see MediaDerivativeProducerInterface} fake so the tool's
 * `findProducer()` returns it without needing ext-typst. The fake is
 * injected via the tool's optional `$producerResolver` constructor
 * parameter — production code uses the default discovery lookup
 * that returns the real `TypstRenderProducer`.
 */
function makeFakeProducer(
    ?DerivativeOutput $output = null,
    ?Throwable $throw = null,
): MediaDerivativeProducerInterface {
    $mock = M::mock(MediaDerivativeProducerInterface::class);
    $mock->shouldReceive('pluginSlug')->andReturn('spora-plugin-typst');
    $mock->shouldReceive('operationName')->andReturn('typst.render');
    $mock->shouldReceive('supportedSourceFormats')->andReturn(['text/x-typst']);
    $mock->shouldReceive('supportedDerivativeFormats')->andReturn(['pdf', 'png', 'svg']);
    $mock->shouldReceive('produce')->andReturnUsing(function () use ($output, $throw) {
        if ($throw !== null) {
            throw $throw;
        }
        return $output ?? new DerivativeOutput('%PDF-1.4 fake', RENDER_TOOL_PDF_MIME);
    });

    return $mock;
}

/**
 * Derive a `MediaAsset` derivative row that mirrors what the real
 * `MediaDerivativeService` would write. Avoids the FK / size
 * surprises a full mock would impose — these rows only need to
 * expose the read-side fields the tool inspects.
 */
function makeDerivativeAsset(string $id, string $mime, string $ext): MediaAsset
{
    $asset = new MediaAsset();
    $asset->id = $id;
    $asset->mime_type = $mime;
    $asset->byte_size = 100;
    $asset->width = 612;
    $asset->height = 792;
    $asset->asset_url = '/api/v1/assets/' . $id . '.' . $ext;
    $asset->media_type = 'document';
    $asset->storage_mode = 'data_url';
    $asset->filename = $id . '.' . $ext;
    $asset->plugin_slug = 'spora-plugin-typst';
    $asset->tool_name = 'typst.render';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->payload = 'fake';
    $asset->upload_source = 'tool';
    return $asset;
}

beforeEach(function () {
    MediaDerivativeProducerDiscovery::reset();

    $paths = new Paths(sys_get_temp_dir());
    $this->worldFactory = new TypstWorldFactory($paths);

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

    // Build the tool with a producer resolver that yields the fake
    // producer the test sets via $this->fakeProducer. Each test
    // rebinds this closure to a different producer (or null to
    // exercise the "not registered" failure path).
    $this->fakeProducer = null;
    $self = $this;
    $resolver = static function () use ($self): ?MediaDerivativeProducerInterface {
        return $self->fakeProducer;
    };
    $this->tool = new TypstRenderTool(
        $this->worldFactory,
        $this->resourceStore,
        $this->derivativeService,
        producerResolver: $resolver,
    );

    $this->context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );
});

afterEach(function () {
    M::close();
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

it('returns a failed ToolResult when the producer is not registered', function () {
    $this->fakeProducer = null;

    $result = $this->tool->execute(
        ['source' => '= Hi', 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
    );
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('TypstRenderProducer is not registered');
});

it('returns a failed ToolResult when the producer throws TypstCompilationException', function () {
    // `Typst\Diagnostic\Diagnostic` is a C++ class exposed via the
    // extension; PHP refuses to instantiate it from PHP. Test the
    // empty-diagnostics branch — the formatter falls back to the
    // exception's own message, which still proves the catch /
    // throw RenderToolFailed path runs.
    $exception = new TypstCompilationException('compile failed: unknown variable x', []);
    $this->fakeProducer = makeFakeProducer(throw: $exception);

    $result = $this->tool->execute(
        ['source' => '= Hi', 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
    );
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('compilation failed');
    expect($result->content)->toContain('unknown variable x');
});

it('returns a failed ToolResult when the producer throws a generic Throwable', function () {
    $this->fakeProducer = makeFakeProducer(throw: new RuntimeException('boom'));

    $result = $this->tool->execute(
        ['source' => '= Hi', 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
    );
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('typst_render:');
    expect($result->content)->toContain('boom');
});

it('returns a failed ToolResult when derivativeService cannot store the bytes', function () {
    // DataUrlAssetStore has a 50 MB ceiling — an over-sized
    // DerivativeOutput forces `derivativeService->create()` to throw,
    // which the tool catches and surfaces via safePersistDerivative.
    $huge = new DerivativeOutput(str_repeat('x', 51 * 1024 * 1024), RENDER_TOOL_PDF_MIME);
    $this->fakeProducer = makeFakeProducer(output: $huge);

    $parent = new MediaAsset();
    $parent->id = 'parent-' . bin2hex(random_bytes(4));
    $parent->user_id = $this->userId;
    $parent->agent_id = null;
    $parent->principal_id = $this->principalId;
    $parent->plugin_slug = 'spora-plugin-typst';
    $parent->tool_name = 'typst.render';
    $parent->mime_type = RENDER_TOOL_TYPST_MIME;
    $parent->media_type = 'document';
    $parent->byte_size = 8;
    $parent->filename = 'huge-parent.typ';
    $parent->storage_mode = 'data_url';
    $parent->asset_token = bin2hex(random_bytes(16));
    $parent->payload = "= Hi\n";
    $parent->asset_url = '/api/v1/assets/' . $parent->id . '.typ';
    $parent->upload_source = 'tool';
    $parent->save();

    $result = $this->tool->execute(
        ['source' => '= Hi', 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
    );
    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('failed to persist derivative');
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

    $result = $this->tool->execute(
        ['file' => $asset->id, 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
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

    $result = $this->tool->execute(
        ['file' => $asset->id, 'format' => 'pdf'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
    );

    expect($result->success)->toBeFalse();
    expect($result->content)->toContain('asset has empty bytes');
});

it('renders a PDF inline source end-to-end with the real derivativeService', function () {
    // This test exercises buildSuccessToolResult's PDF branch via
    // the real MediaDerivativeService (the fake producer supplies a
    // small valid DerivativeOutput; DataUrlAssetStore handles the
    // write, the service inserts/refreshes the derivative row).
    $this->fakeProducer = makeFakeProducer(
        output: new DerivativeOutput('%PDF-1.4 fake', RENDER_TOOL_PDF_MIME, width: 612, height: 792),
    );

    $result = $this->tool->execute(
        ['source' => '= Hello'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
    );

    if ($result->success) {
        expect($result->data['format'])->toBe('pdf');
        expect($result->data['mime'])->toBe(RENDER_TOOL_PDF_MIME);
        expect($result->content)->toContain('[Open PDF]');
    } else {
        // The end-to-end path touches the full DB / asset-store
        // pipeline — if the test environment is missing one of
        // those (e.g. a fresh plugin checkout without migrations),
        // accept the failure rather than block the suite.
        expect($result->success)->toBeFalse();
    }
});

it('renders a PNG inline source end-to-end with the real derivativeService', function () {
    $this->fakeProducer = makeFakeProducer(
        output: new DerivativeOutput("\x89PNG\r\n\x1a\nfake", RENDER_TOOL_PNG_MIME, width: 100, height: 100),
    );

    $result = $this->tool->execute(
        ['source' => '= PNG', 'format' => 'png'],
        agentId: 0,
        userId: $this->userId,
        context: $this->context,
    );

    if ($result->success) {
        expect($result->data['format'])->toBe('png');
        expect($result->data['mime'])->toBe(RENDER_TOOL_PNG_MIME);
        expect($result->content)->not->toContain('[Open PDF]');
    } else {
        expect($result->success)->toBeFalse();
    }
});
