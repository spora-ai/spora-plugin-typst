<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Plugins\Typst\Services\TypstResourcePaths;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/typst-image-test-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0o755, true);
    mkdir($this->tempDir . '/storage', 0o755, true);

    $this->paths = new Paths($this->tempDir);
    $resourcePaths = new TypstResourcePaths($this->paths, principalId: 7);
    $this->store = new TypstImageStore($resourcePaths);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->tempDir);
    }
});

it('persists a PNG image under <storage>/typst/<principal>/', function () {
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
    );
    expect($png)->not->toBeFalse();

    $row = $this->store->write($png, 'image/png', 'logo.png');
    expect($row['name'])->toBe('logo.png');
    expect($row['mime'])->toBe('image/png');
    expect($row['size'])->toBe(strlen($png));
    // Per-principal layout: images live directly under
    // <storage>/typst/<principal>/, not in an images/ subdir.
    expect(is_file($this->tempDir . '/storage/typst/7/logo.png'))->toBeTrue();
});

it('mints a sensible default filename when none is supplied', function () {
    $png = "\x89PNG\r\n\x1a\n" . random_bytes(50);
    $row = $this->store->write($png, 'image/png', null);

    expect($row['name'])->toStartWith('typst-image-');
    expect($row['name'])->toEndWith('.png');
});

it('stores SVG with image/svg+xml', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';
    $row = $this->store->write($svg, 'image/svg+xml', 'icon.svg');

    expect($row['name'])->toBe('icon.svg');
    expect($row['mime'])->toBe('image/svg+xml');
    expect($row['size'])->toBe(strlen($svg));
});

it('rejects an unsupported mime', function () {
    expect(fn() => $this->store->write('x', 'image/gif', 'logo.gif'))
        ->toThrow(RuntimeException::class, 'not allowed');
});

it('rejects an empty payload', function () {
    expect(fn() => $this->store->write('', 'image/png', 'logo.png'))
        ->toThrow(RuntimeException::class, 'empty payload');
});

it('rejects an oversize payload', function () {
    $big = str_repeat('a', TypstImageStore::MAX_BYTES + 1);
    expect(fn() => $this->store->write($big, 'image/png', 'big.png'))
        ->toThrow(RuntimeException::class, 'exceeds');
});

it('isolates lists by principal_id', function () {
    // Write a file under principal 7 directly so we don't depend on
    // the public write() method's principal-resolution flow.
    $dir7 = $this->tempDir . '/storage/typst/7';
    $dir8 = $this->tempDir . '/storage/typst/8';
    mkdir($dir7, 0o755, true);
    mkdir($dir8, 0o755, true);
    file_put_contents($dir7 . '/a.png', 'x');
    file_put_contents($dir7 . '/b.png', 'x');
    file_put_contents($dir8 . '/other.png', 'x');

    $names = array_map(static fn(array $row): string => $row['name'], $this->store->list());
    expect($names)->toContain('a.png');
    expect($names)->toContain('b.png');
    expect($names)->not->toContain('other.png');
});

it('deletes an image by basename', function () {
    $this->store->write('x', 'image/png', 'doomed.png');
    $this->store->delete('doomed.png');

    expect(is_file($this->tempDir . '/storage/typst/7/doomed.png'))->toBeFalse();
});

it('refuses to delete a missing image', function () {
    expect(fn() => $this->store->delete('nonexistent.png'))
        ->toThrow(RuntimeException::class, 'not found');
});

it('rejects path-traversal basenames on read', function () {
    expect(fn() => $this->store->read('../etc/passwd'))
        ->toThrow(RuntimeException::class, 'invalid basename');
});

it('publicUrl is /api/v1/typst/images/{basename}', function () {
    expect($this->store->publicUrl('logo.png'))
        ->toBe('/api/v1/typst/images/logo.png');
    expect($this->store->publicUrl('a b.png'))
        ->toBe('/api/v1/typst/images/a%20b.png');
});
