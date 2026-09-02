<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstWorldFactory;

beforeEach(function () {
    $this->paths = new Paths(sys_get_temp_dir());
    $this->resourcePaths = new TypstResourcePaths($this->paths, principalId: 1);
    $this->factory = new TypstWorldFactory($this->paths);
});

it('builds a world + compiler + inspector triple for the given principal', function () {
    if (!extension_loaded('typst')) {
        $this->markTestSkipped('ext-typst is not loaded');
    }
    $stack = $this->factory->build(1);
    expect($stack)->toHaveKeys(['world', 'compiler', 'inspector']);
    expect($stack['world'])->toBeInstanceOf(Typst\World::class);
    expect($stack['compiler'])->toBeInstanceOf(Typst\Compiler::class);
    expect($stack['inspector'])->toBeInstanceOf(Typst\Inspector::class);
});

it('exposes the union of skill and principal font directories', function () {
    // Set up a tier-2 directory with at least one font so the
    // factory can return a non-empty list during tests.
    $principalDir = $this->resourcePaths->principalFontDirectory();
    @mkdir($principalDir, 0o755, true);
    file_put_contents($principalDir . '/Test.otf', 'fake');

    $dirs = $this->factory->fontDirs($this->resourcePaths);
    expect($dirs)->not->toBeEmpty();
    // Each entry must be an absolute realpath that exists.
    foreach ($dirs as $dir) {
        expect(is_dir($dir))->toBeTrue();
    }

    @unlink($principalDir . '/Test.otf');
    @rmdir($principalDir);
});

it('always returns at least the plugin-shipped font directory', function () {
    // The plugin ships Inter OFL fonts in `skills/typst/fonts/`,
    // which `TypstResourcePaths::skillFontDirectory()` resolves via
    // `InstalledVersions::getInstallPath()`. The factory's
    // `fontDirs()` must always include this tier-1 dir regardless
    // of the operator's tier-2 state — that's the "Inter OFL is
    // always there" guarantee the README promises.
    $dirs = $this->factory->fontDirs($this->resourcePaths);
    expect($dirs)->not->toBeEmpty();
    $realpathSkills = realpath($this->resourcePaths->skillFontDirectory());
    expect($realpathSkills)->not->toBeFalse();
    expect($dirs)->toContain($realpathSkills);
});
