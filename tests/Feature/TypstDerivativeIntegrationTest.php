<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;

beforeEach(function () {
    if (!extension_loaded('typst')) {
        $this->skip(true, 'ext-typst is not loaded');
    }
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(TypstRenderProducer::class);

    $paths = new TypstResourcePaths(
        new Spora\Core\Paths(sys_get_temp_dir()),
        principalId: 1,
    );
    $this->producer = new TypstRenderProducer(new TypstWorldFactory($paths));
});

it('registers itself with the discovery registry at boot', function () {
    expect(MediaDerivativeProducerDiscovery::all())
        ->toContain(TypstRenderProducer::class);
});

it('produces a derivative through MediaDerivativeService and persists it', function () {
    $parent = new MediaAsset();
    $parent->id = 'parent-' . bin2hex(random_bytes(4));
    $parent->user_id = null;
    $parent->agent_id = null;
    $parent->principal_id = null;
    $parent->plugin_slug = 'spora-plugin-typst';
    $parent->tool_name = 'typst.render';
    $parent->mime_type = 'text/x-typst';
    $parent->media_type = 'document';
    $parent->byte_size = 8;
    $parent->filename = 'inline-source.typ';
    $parent->storage_mode = 'data_url';
    $parent->asset_token = bin2hex(random_bytes(16));
    $parent->payload = "= Hello\n";
    $parent->asset_url = '/api/v1/assets/' . $parent->id . '.typ';
    $parent->upload_source = 'tool';
    $parent->save();

    $output = $this->producer->produce($parent, 'pdf', []);
    expect($output->mime)->toBe('application/pdf');

    $service = new MediaDerivativeService(
        new Spora\Services\DataUrlAssetStore(),
        new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()),
    );
    $derivative = $service->create(
        parent: $parent,
        output: $output,
        format: 'pdf',
        producerPlugin: 'spora-plugin-typst',
        producerOperation: 'typst.render',
        userId: null,
    );

    expect($derivative->mime_type)->toBe('application/pdf');
    expect(strlen((string) $derivative->payload))->toBeGreaterThan(100);

    // The natural-key uniqueness on (parent_id, format, producer_plugin, producer_operation)
    // means a second create() refreshes the existing row's bytes
    // rather than inserting a new one.
    $derivative2 = $service->create(
        parent: $parent,
        output: $output,
        format: 'pdf',
        producerPlugin: 'spora-plugin-typst',
        producerOperation: 'typst.render',
        userId: null,
    );
    expect($derivative2->id)->toBe($derivative->id);

    $joinCount = Capsule::table('media_derivatives')
        ->where('parent_id', $parent->id)
        ->where('format', 'pdf')
        ->count();
    expect($joinCount)->toBe(1);
});
