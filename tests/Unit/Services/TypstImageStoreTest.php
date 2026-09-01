<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Services\DataUrlAssetStore;

beforeEach(function () {
    $this->store = new TypstImageStore(new DataUrlAssetStore());

    // Create two synthetic principals — `user_id`/`group_id` are both
    // nullable so a "synthetic" row satisfies the FKs cleanly. Only
    // one row can have null user_id (unique index), so we line up
    // ids 1 and 2 with explicit user_ids here.
    Capsule::table('principals')->insert([
        ['id' => 1, 'type' => 'user', 'user_id' => null, 'group_id' => null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
        ['id' => 2, 'type' => 'user', 'user_id' => null, 'group_id' => null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
    ]);
});

it('persists a PNG image as a media_assets row with the right plugin attribution', function () {
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    );
    expect($png)->not->toBeFalse();

    $asset = $this->store->create($png, 'image/png', [
        'principal_id' => 1,
        'user_id'      => null,
        'filename'     => 'logo.png',
    ]);

    expect($asset->plugin_slug)->toBe('spora-plugin-typst');
    expect($asset->tool_name)->toBe('typst.image');
    expect($asset->mime_type)->toBe('image/png');
    expect($asset->media_type)->toBe('image');
    expect($asset->filename)->toBe('logo.png');
    expect($asset->principal_id)->toBe(1);
    expect($asset->byte_size)->toBe(strlen($png));
    expect($asset->asset_url)->toContain($asset->id);
    expect($asset->asset_url)->toEndWith('.png');
    expect($asset->publicUrl())->toEndWith('.png');
});

it('mints a sensible default filename when none is supplied', function () {
    $png = "\x89PNG\r\n\x1a\n" . random_bytes(50);
    $asset = $this->store->create($png, 'image/png', ['principal_id' => 1]);

    expect($asset->filename)->toBe('typst-image.png');
});

it('stores SVG as image/svg+xml with the svg extension', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';
    $asset = $this->store->create($svg, 'image/svg+xml', ['principal_id' => 1]);

    expect($asset->mime_type)->toBe('image/svg+xml');
    expect($asset->asset_url)->toEndWith('.svg');
});

it('rejects an unsupported mime', function () {
    expect(fn() => $this->store->create('x', 'image/gif', ['principal_id' => 1]))
        ->toThrow(RuntimeException::class, 'not allowed');
});

it('rejects an empty payload', function () {
    expect(fn() => $this->store->create('', 'image/png', ['principal_id' => 1]))
        ->toThrow(RuntimeException::class, 'empty payload');
});

it('rejects an oversize payload', function () {
    $big = str_repeat('a', TypstImageStore::MAX_BYTES + 1);
    expect(fn() => $this->store->create($big, 'image/png', ['principal_id' => 1]))
        ->toThrow(RuntimeException::class, 'exceeds');
});

it('rejects a zero principal_id', function () {
    expect(fn() => $this->store->create('x', 'image/png', ['principal_id' => 0]))
        ->toThrow(RuntimeException::class, 'positive integer');
});

it('isolates lists by principal_id', function () {
    $a = $this->store->create('x', 'image/png', ['principal_id' => 1, 'filename' => 'a.png']);
    $b = $this->store->create('x', 'image/png', ['principal_id' => 1, 'filename' => 'b.png']);
    // Different principal — must not show up.
    $this->store->create('x', 'image/png', ['principal_id' => 2, 'filename' => 'other.png']);

    $rows = $this->store->listFor(1);
    expect($rows)->toHaveCount(2);
    $ids = array_map(static fn(Spora\Models\MediaAsset $row): string => $row->id, $rows);
    expect($ids)->toContain($a->id);
    expect($ids)->toContain($b->id);

    foreach ($rows as $row) {
        expect($row->principal_id)->toBe(1);
        expect($row->plugin_slug)->toBe('spora-plugin-typst');
        expect($row->tool_name)->toBe('typst.image');
    }
});

it('deletes an image by id', function () {
    $asset = $this->store->create('x', 'image/png', ['principal_id' => 1]);
    $this->store->delete($asset->id, 1);

    expect(Spora\Models\MediaAsset::query()->find($asset->id))->toBeNull();
});

it('refuses to delete a missing image', function () {
    expect(fn() => $this->store->delete('nonexistent-id', 1))
        ->toThrow(RuntimeException::class, 'not found');
});

it('refuses to delete another principal\'s image', function () {
    $asset = $this->store->create('x', 'image/png', ['principal_id' => 1]);
    expect(fn() => $this->store->delete($asset->id, 2))
        ->toThrow(RuntimeException::class, 'not found');
    expect(Spora\Models\MediaAsset::query()->find($asset->id))->not->toBeNull();
});
