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

it('exposes a prelude that sets default text + math fonts', function () {
    $prelude = $this->factory->prelude();
    // Default text font cascade covers Inter (the README's "always
    // there" font) + DejaVu Sans/Serif for principals that haven't
    // uploaded Inter yet.
    expect($prelude)->toContain('Inter');
    expect($prelude)->toContain('DejaVu Sans');
    // Math font fallback to Latin Modern Math — bundled in
    // skills/typst/fonts/latinmodern-math.otf so ext-typst's
    // auto-discovery can resolve math blocks without the user
    // declaring it.
    expect($prelude)->toContain('Latin Modern Math');
    expect($prelude)->toContain('math.equation');
});

it('wraps user source with the prelude but keeps the user source byte-for-byte at the tail', function () {
    $source = "= Hello\n\$x = 1$\n";
    $wrapped = $this->factory->wrapSource($source);
    expect($wrapped)->toStartWith('#set text(font:');
    // The user's source must be a verbatim suffix — the prelude
    // doesn't comment, escape, or otherwise touch their bytes.
    expect(substr($wrapped, -strlen($source)))->toBe($source);
});
