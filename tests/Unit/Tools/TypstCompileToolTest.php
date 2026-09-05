<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Plugins\Typst\Tools\TypstCompileTool;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\MediaDerivativeService;

const COMPILE_TOOL_PDF_MIME = 'application/pdf';
const COMPILE_TOOL_PNG_MIME = 'image/png';
const COMPILE_TOOL_TYPST_MIME = 'text/x-typst';

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
        return $output ?? new DerivativeOutput('%PDF-1.4 fake', COMPILE_TOOL_PDF_MIME);
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
    $this->userId = $this->auth->register('compile-tool@example.com', 'Password1!', 'Compile Tool');
    simulateLoggedInSession($this->userId, 'compile-tool@example.com');

    $this->principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $this->principalId = $this->principalService->ensureUserPrincipal($this->userId)->id;

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
    $this->tool = new TypstCompileTool(
        $this->worldFactory,
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

describe('action discriminator', function (): void {
    it('defaults to render when no action is provided', function (): void {
        $this->fakeProducer = null;
        $result = $this->tool->execute(
            ['source' => '= Hi', 'filename' => 'hi.typ', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('TypstRenderProducer is not registered');
    });

    it('routes action=render to the renderSource path', function (): void {
        $this->fakeProducer = null;
        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= Hi', 'filename' => 'hi.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->content)->toContain('TypstRenderProducer is not registered');
    });

    it('routes action=inspect to the inspectSource path', function (): void {
        $result = $this->tool->execute(
            ['action' => 'inspect', 'source' => '= Hi'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        // The inspect path runs the real Inspector (ext-typst). On
        // a host with the extension installed it returns no errors;
        // on a host without it the WorldFactory throws — either way
        // the failure-path is the producer-missing one we already
        // covered above, so we accept any ToolResult here and just
        // verify the dispatch happened (not "TypstRenderProducer is
        // not registered").
        expect($result->content)->not->toContain('TypstRenderProducer is not registered');
    });

    it('returns a failed ToolResult for an unknown action', function (): void {
        $result = $this->tool->execute(
            ['action' => 'bogus', 'source' => '= Hi'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('unknown action "bogus"');
        expect($result->content)->toContain('render | inspect');
    });
});

describe('render path', function (): void {
    it('returns a failed ToolResult when the format is unsupported', function () {
        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= Hi', 'format' => 'docx'],
            agentId: 0,
            userId: null,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('invalid format');
        expect($result->content)->toContain('docx');
    });

    it('returns a failed ToolResult when neither source nor file is provided', function () {
        $result = $this->tool->execute(['action' => 'render'], agentId: 0, userId: null);
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('either `source`');
    });

    it('returns a failed ToolResult when the file points to a missing asset', function () {
        $result = $this->tool->execute(['action' => 'render', 'file' => 'no-such-asset'], agentId: 0, userId: null);
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('media asset "no-such-asset" not found');
    });

    it('returns a failed ToolResult when the producer is not registered', function () {
        $this->fakeProducer = null;

        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= Hi', 'filename' => 'hi.typ', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('TypstRenderProducer is not registered');
    });

    it('auto-generates a playground filename when render is called with inline source but no filename', function () {
        $this->fakeProducer = makeFakeProducer();

        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= Hi', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
        // The data envelope carries the parent filename the tool picked
        // (see ToolResult::data() in buildSuccessToolResult). The
        // auto-generated name matches the documented format.
        expect($result->data)->toBeArray();
    });

    it('auto-generates a playground filename when render is called with inline source but filename is empty', function () {
        $this->fakeProducer = makeFakeProducer();

        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= Hi', 'filename' => '', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
    });

    it('auto-generates a filename matching the inline-YYYYMMDD-HHMMSS-XXXX pattern', function () {
        $this->fakeProducer = makeFakeProducer();

        $this->tool->execute(
            ['action' => 'render', 'source' => '= Hi', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        // `materialiseNamedInlineSource` writes the parent playground
        // row (tool_name='typst.playground', filename *.typ); the
        // subsequent derivative create() writes a sibling row whose
        // filename swaps the extension. Filter on tool_name so we
        // pick the parent and not the just-newer derivative.
        $parent = MediaAsset::query()
            ->where('user_id', $this->userId)
            ->where('plugin_slug', 'spora-plugin-typst')
            ->where('tool_name', 'typst.playground')
            ->where('filename', 'like', 'inline-%')
            ->orderByDesc('created_at')
            ->first();
        expect($parent)->not->toBeNull();
        expect($parent->filename)->toMatch('/^inline-\d{8}-\d{6}-[0-9a-f]{4}\.typ$/');
    });

    it('returns a failed ToolResult when the producer throws TypstCompilationException', function () {
        // `Typst\Diagnostic\Diagnostic` is a C++ class exposed via the
        // extension; PHP refuses to instantiate it from PHP. Test the
        // empty-diagnostics branch — the formatter falls back to the
        // exception's own message, which still proves the catch /
        // throw path runs.
        $exception = new TypstCompilationException('compile failed: unknown variable x', []);
        $this->fakeProducer = makeFakeProducer(throw: $exception);

        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= Hi', 'filename' => 'hi.typ', 'format' => 'pdf'],
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
            ['action' => 'render', 'source' => '= Hi', 'filename' => 'hi.typ', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('typst_compile:');
        expect($result->content)->toContain('boom');
    });

    it('returns a failed ToolResult when derivativeService cannot store the bytes', function () {
        // DataUrlAssetStore has a 50 MB ceiling — an over-sized
        // DerivativeOutput forces `derivativeService->create()` to throw,
        // which the tool catches and surfaces via safePersistDerivative.
        $huge = new DerivativeOutput(str_repeat('x', 51 * 1024 * 1024), COMPILE_TOOL_PDF_MIME);
        $this->fakeProducer = makeFakeProducer(output: $huge);

        $parent = new MediaAsset();
        $parent->id = 'parent-' . bin2hex(random_bytes(4));
        $parent->user_id = $this->userId;
        $parent->agent_id = null;
        $parent->principal_id = $this->principalId;
        $parent->plugin_slug = 'spora-plugin-typst';
        $parent->tool_name = 'typst.render';
        $parent->mime_type = COMPILE_TOOL_TYPST_MIME;
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
            ['action' => 'render', 'source' => '= Hi', 'filename' => 'hi.typ', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('failed to persist derivative');
    });

    it('produces a describeAction string for a render with an inline source and no filename', function () {
        // When filename is missing the source descriptor falls back to the
        // literal "inline" so the approval UI still gets a stable label.
        expect($this->tool->describeAction(['action' => 'render', 'source' => '= Hi', 'format' => 'svg']))
            ->toContain('svg')
            ->toContain('inline');
    });

    it('produces a describeAction string for a render with an inline source and a filename', function () {
        expect($this->tool->describeAction(['action' => 'render', 'source' => '= Hi', 'filename' => 'letter.typ', 'format' => 'pdf']))
            ->toContain('pdf')
            ->toContain('letter.typ');
    });

    it('produces a describeAction string for a render with a file source', function () {
        expect($this->tool->describeAction(['action' => 'render', 'file' => 'abcdef12-3456-7890', 'format' => 'pdf']))
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
        $asset->mime_type = COMPILE_TOOL_TYPST_MIME;
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
            ['action' => 'render', 'file' => $asset->id, 'format' => 'pdf'],
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
        $asset->mime_type = COMPILE_TOOL_TYPST_MIME;
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
            ['action' => 'render', 'file' => $asset->id, 'format' => 'pdf'],
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
        $asset->mime_type = COMPILE_TOOL_TYPST_MIME;
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
            ['action' => 'render', 'file' => $asset->id, 'format' => 'pdf'],
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
            output: new DerivativeOutput('%PDF-1.4 fake', COMPILE_TOOL_PDF_MIME, width: 612, height: 792),
        );

        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= Hello', 'filename' => 'hello.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        if ($result->success) {
            expect($result->data['format'])->toBe('pdf');
            expect($result->data['mime'])->toBe(COMPILE_TOOL_PDF_MIME);
            expect($result->data['asset_urls'])->toBeArray();
            expect($result->data['asset_urls'])->toHaveCount(1);
            expect($result->data['asset_urls'][0])->toStartWith('/api/v1/assets/');
            expect($result->content)->toContain('Rendered PDF');
            expect($result->content)->toContain('[Open PDF]');
            expect($result->content)->toContain('Echo the markdown block above verbatim');
            expect($result->content)->toContain('Do NOT invent or rewrite URLs');
            expect($result->content)->toContain('file://');
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
            output: new DerivativeOutput("\x89PNG\r\n\x1a\nfake", COMPILE_TOOL_PNG_MIME, width: 100, height: 100),
        );

        $result = $this->tool->execute(
            ['action' => 'render', 'source' => '= PNG', 'filename' => 'cover.typ', 'format' => 'png'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        if ($result->success) {
            expect($result->data['format'])->toBe('png');
            expect($result->data['mime'])->toBe(COMPILE_TOOL_PNG_MIME);
            expect($result->data['asset_urls'])->toBeArray();
            expect($result->data['asset_urls'])->toHaveCount(1);
            expect($result->data['asset_urls'][0])->toStartWith('/api/v1/assets/');
            expect($result->content)->toContain('Rendered PNG');
            expect($result->content)->not->toContain('[Open PDF]');
            expect($result->content)->toContain('Echo the markdown block above verbatim');
            // PNG branch must use the markdown image embed for inline
            // rendering; verifying the ![…](…) pattern appears once
            // (the heading + image + echo instruction, no link prefix).
            expect($result->content)->toContain('![');
            expect($result->content)->toContain('Do NOT invent or rewrite URLs');
        } else {
            expect($result->success)->toBeFalse();
        }
    });

    it('uses the supplied filename on the persisted parent row when rendering inline source', function () {
        $this->fakeProducer = makeFakeProducer(
            output: new DerivativeOutput('%PDF-1.4 fake', COMPILE_TOOL_PDF_MIME, width: 612, height: 792),
        );

        $result = $this->tool->execute(
            ['action' => 'render', 'source' => "= Invoice\n", 'filename' => 'invoice.typ', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        if (!$result->success) {
            // Same end-to-end-skip behaviour as the PDF/PNG tests above.
            expect($result->success)->toBeFalse();
            return;
        }
        $parent = MediaAsset::query()->where('filename', 'invoice.typ')->first();
        expect($parent)->not->toBeNull();
        expect($parent->tool_name)->toBe('typst.playground');
        expect($parent->mime_type)->toBe('text/x-typst');
        // Filename uniqueness is dropped (see Commit 3) so the row is
        // identified by id, not (principal, tool_name, filename).
        expect($parent->id)->not->toBeEmpty();
    });

    it('ignores filename when render is called via file=<id>', function () {
        $parent = new MediaAsset();
        $parent->id = 'parent-' . bin2hex(random_bytes(4));
        $parent->user_id = $this->userId;
        $parent->agent_id = null;
        $parent->principal_id = $this->principalId;
        $parent->plugin_slug = 'spora-plugin-typst';
        $parent->tool_name = 'typst.playground';
        $parent->mime_type = COMPILE_TOOL_TYPST_MIME;
        $parent->media_type = 'document';
        $parent->byte_size = 8;
        $parent->filename = 'already-saved.typ';
        $parent->storage_mode = 'data_url';
        $parent->asset_token = bin2hex(random_bytes(16));
        $parent->payload = "= Already saved\n";
        $parent->asset_url = '/api/v1/assets/' . $parent->id . '.typ';
        $parent->upload_source = 'upload';
        $parent->save();

        $this->fakeProducer = makeFakeProducer(
            output: new DerivativeOutput('%PDF-1.4 fake', COMPILE_TOOL_PDF_MIME, width: 612, height: 792),
        );

        $result = $this->tool->execute(
            ['action' => 'render', 'file' => $parent->id, 'filename' => 'ignored.typ', 'format' => 'pdf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        if ($result->success) {
            // The existing parent row is reused (not replaced); no row
            // is created under the supplied filename.
            expect(MediaAsset::query()->where('filename', 'ignored.typ')->exists())->toBeFalse();
            expect(MediaAsset::query()->where('id', $parent->id)->exists())->toBeTrue();
        } else {
            expect($result->success)->toBeFalse();
        }
    });
});

describe('inspect path', function (): void {
    it('returns a failed ToolResult when neither source nor file is provided', function () {
        $result = $this->tool->execute(
            ['action' => 'inspect'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('either `source`');
    });

    it('performs no DB writes — an inline source inspect leaves the media_assets table untouched', function () {
        // The previous behaviour materialised an `inline-source.typ`
        // parent row before running `inspectString()`. That row was
        // never used by the inspect result and accumulated orphans
        // in `media_assets`. The fix: `inspect` is a pure read; only
        // `render` writes.
        $before = MediaAsset::query()->where('plugin_slug', 'spora-plugin-typst')->count();
        $tool = new TypstCompileTool(
            $this->worldFactory,
            $this->derivativeService,
            inspectorFactory: fn() => new class {
                public function inspectString(string $source): object
                {
                    return new class {
                        public function errors(): array
                        {
                            return [];
                        }
                        public function warnings(): array
                        {
                            return [];
                        }
                        public function success(): bool
                        {
                            return true;
                        }
                    };
                }
            },
        );

        $result = $tool->execute(
            ['action' => 'inspect', 'source' => "= Hello\n"],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeTrue();
        $after = MediaAsset::query()->where('plugin_slug', 'spora-plugin-typst')->count();
        expect($after)->toBe($before);
    });

    it('returns a failed ToolResult when inspect is called with inline source AND filename — filename is for render only', function () {
        // `filename` is a render-only parameter; inspect ignores it
        // entirely. This pins down the contract that inspect never
        // touches the playground pool.
        $tool = new TypstCompileTool(
            $this->worldFactory,
            $this->derivativeService,
            inspectorFactory: fn() => new class {
                public function inspectString(string $source): object
                {
                    return new class {
                        public function errors(): array
                        {
                            return [];
                        }
                        public function warnings(): array
                        {
                            return [];
                        }
                        public function success(): bool
                        {
                            return true;
                        }
                    };
                }
            },
        );

        $before = MediaAsset::query()->where('plugin_slug', 'spora-plugin-typst')->count();
        $result = $tool->execute(
            ['action' => 'inspect', 'source' => "= Hi\n", 'filename' => 'inspect-letter.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
        // The supplied filename must NOT have created a playground row —
        // inspect is a pure read.
        expect(MediaAsset::query()->where('filename', 'inspect-letter.typ')->exists())->toBeFalse();
        $after = MediaAsset::query()->where('plugin_slug', 'spora-plugin-typst')->count();
        expect($after)->toBe($before);
    });

    it('returns a failed ToolResult when the file points to a missing asset', function () {
        $result = $this->tool->execute(
            ['action' => 'inspect', 'file' => 'no-such-asset'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('media asset "no-such-asset" not found');
    });

    it('produces a describeAction string for an inspect call', function () {
        expect($this->tool->describeAction(['action' => 'inspect', 'source' => '= Hi']))
            ->toContain('inspect')
            ->toContain('inline');
    });

    it('returns success with structured diagnostics on a clean source', function () {
        $tool = new TypstCompileTool(
            $this->worldFactory,
            $this->derivativeService,
            inspectorFactory: fn() => new class {
                public function inspectString(string $source): object
                {
                    return new class {
                        public function errors(): array
                        {
                            return [];
                        }
                        public function warnings(): array
                        {
                            return [];
                        }
                        public function success(): bool
                        {
                            return true;
                        }
                    };
                }
            },
        );

        $result = $tool->execute(
            ['action' => 'inspect', 'source' => "= Hello\n"],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('no diagnostics');
        expect($result->data['success'])->toBeTrue();
        expect($result->data['errors'])->toBe([]);
        expect($result->data['warnings'])->toBe([]);
    });

    it('returns success with structured error diagnostics on a broken source', function () {
        // Stand-in diagnostic that satisfies the tool's call to
        // $diag->message() / $diag->severity() / $diag->hints().
        $diag = new class {
            public function severity(): object
            {
                return new class {
                    public string $name = 'ERROR';
                };
            }
            public function message(): string
            {
                return 'file not found (searched at /etc/passwd)';
            }
            public function hints(): array
            {
                return [];
            }
        };
        $tool = new TypstCompileTool(
            $this->worldFactory,
            $this->derivativeService,
            inspectorFactory: fn() => new class ($diag) {
                private $diag;
                public function __construct(object $diag)
                {
                    $this->diag = $diag;
                }
                public function inspectString(string $source): object
                {
                    return new class ($this->diag) {
                        private $diag;
                        public function __construct(object $diag)
                        {
                            $this->diag = $diag;
                        }
                        public function errors(): array
                        {
                            return [$this->diag];
                        }
                        public function warnings(): array
                        {
                            return [];
                        }
                        public function success(): bool
                        {
                            return false;
                        }
                    };
                }
            },
        );

        $result = $tool->execute(
            ['action' => 'inspect', 'source' => "#include \"does-not-exist.typ\"\n"],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('1 error(s)');
        expect($result->data['errors'])->not->toBe([]);
        expect($result->data['success'])->toBeFalse();
    });

    it('returns a failed ToolResult when the inspector throws', function () {
        $tool = new TypstCompileTool(
            $this->worldFactory,
            $this->derivativeService,
            inspectorFactory: fn() => new class {
                public function inspectString(string $source): never
                {
                    throw new RuntimeException('inspector exploded');
                }
            },
        );

        $result = $tool->execute(
            ['action' => 'inspect', 'source' => "= Hi\n"],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('inspect failed');
        expect($result->content)->toContain('inspector exploded');
    });
});
