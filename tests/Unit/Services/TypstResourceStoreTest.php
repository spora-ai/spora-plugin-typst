<?php

declare(strict_types=1);

const RESOURCE_STORE_FONT_PATH = '/storage/typst/42/fonts/Inter-Black.otf';

use Spora\Core\Paths;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;

beforeEach(function () {
    $this->base = sys_get_temp_dir() . '/typst-store-test-' . bin2hex(random_bytes(4));
    // Per-principal layout: <storage>/typst/<principal>/{fonts,examples}
    @mkdir($this->base . '/storage/typst/42/fonts', 0o755, true);
    @mkdir($this->base . '/storage/typst/42/examples', 0o755, true);
    @mkdir($this->base . '/storage/typst/99/fonts', 0o755, true);
    file_put_contents($this->base . RESOURCE_STORE_FONT_PATH, 'fake-font-bytes');
    file_put_contents($this->base . '/storage/typst/99/fonts/Other.otf', 'different-principal');
    file_put_contents($this->base . '/storage/typst/42/examples/invoice.typ', '= Invoice\n');

    $this->paths = new Paths($this->base);
    $this->resourcePaths = new TypstResourcePaths($this->paths, principalId: 42);
    $this->store = new TypstResourceStore($this->resourcePaths);
});

afterEach(function () {
    @unlink($this->base . RESOURCE_STORE_FONT_PATH);
    @unlink($this->base . '/storage/typst/99/fonts/Other.otf');
    @unlink($this->base . '/storage/typst/42/examples/invoice.typ');
    @rmdir($this->base . '/storage/typst/42/fonts');
    @rmdir($this->base . '/storage/typst/42/examples');
    @rmdir($this->base . '/storage/typst/42');
    @rmdir($this->base . '/storage/typst/99/fonts');
    @rmdir($this->base . '/storage/typst/99');
    @rmdir($this->base . '/storage/typst');
    @rmdir($this->base . '/storage');
    @rmdir($this->base);
});

it('lists principal uploads with their byte size and origin', function () {
    $rows = $this->store->list(TypstResourcePaths::KIND_FONT);
    // The store lists the union of skill-shipped + principal uploads
    // (tier-2 shadows tier-1). Our principal 42 has 1 upload
    // (Inter-Black.otf); the skill tier may add more. Just verify
    // the principal entry is present.
    $principal = array_filter($rows, static fn(array $r): bool => $r['name'] === 'Inter-Black.otf');
    expect($principal)->toHaveCount(1);
    expect(array_values($principal)[0])->toMatchArray([
        'name'   => 'Inter-Black.otf',
        'kind'   => 'font',
        'origin' => 'principal',
    ]);
    expect(array_values($principal)[0]['size'])->toBe(strlen('fake-font-bytes'));
});

it('reads the principal upload bytes verbatim', function () {
    expect($this->store->read(TypstResourcePaths::KIND_FONT, 'Inter-Black.otf'))
        ->toBe('fake-font-bytes');
    expect($this->store->read(TypstResourcePaths::KIND_FONT, 'missing.otf'))
        ->toBeNull();
});

it('writes a new tier-2 resource', function () {
    $path = $this->store->write(TypstResourcePaths::KIND_FONT, 'New.otf', 'hello');
    // Per-principal layout: <storage>/typst/<principal>/fonts/<basename>
    expect($path)->toEndWith('/typst/42/fonts/New.otf');
    expect($this->store->read(TypstResourcePaths::KIND_FONT, 'New.otf'))->toBe('hello');
    @unlink($path);
});

it('overwrites an existing tier-2 resource by basename', function () {
    $path = $this->store->write(TypstResourcePaths::KIND_FONT, 'Inter-Black.otf', 'updated');
    expect($this->store->read(TypstResourcePaths::KIND_FONT, 'Inter-Black.otf'))->toBe('updated');
    // restore for afterEach
    file_put_contents($path, 'fake-font-bytes');
});

it('rejects traversal basenames', function () {
    foreach (['../escape', '/abs', 'sub/dir', '..', '.', ''] as $bad) {
        expect(fn() => $this->store->write(TypstResourcePaths::KIND_FONT, $bad, 'x'))
            ->toThrow(RuntimeException::class);
        expect(fn() => $this->store->delete(TypstResourcePaths::KIND_FONT, $bad))
            ->toThrow(RuntimeException::class);
    }
});

it('rejects oversize payloads', function () {
    $big = str_repeat('a', TypstResourceStore::MAX_BYTES + 1);
    expect(fn() => $this->store->write(TypstResourcePaths::KIND_FONT, 'TooBig.otf', $big))
        ->toThrow(RuntimeException::class);
});

it('refuses to delete a missing tier-2 resource', function () {
    expect(fn() => $this->store->delete(TypstResourcePaths::KIND_FONT, 'NeverExisted.otf'))
        ->toThrow(RuntimeException::class);
});

it('deletes a principal-tier resource', function () {
    $this->store->delete(TypstResourcePaths::KIND_FONT, 'Inter-Black.otf');
    expect($this->store->read(TypstResourcePaths::KIND_FONT, 'Inter-Black.otf'))->toBeNull();
    // re-create for afterEach cleanup
    file_put_contents($this->base . RESOURCE_STORE_FONT_PATH, 'fake-font-bytes');
});
