<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Producers\TypstRenderProducer;
use Spora\Plugins\Typst\Services\TypstWorldFactory;

/**
 * Pins the producer's wrap behaviour without going through ext-typst:
 * the producer threads the world factory's `wrapSource()` output into
 * both the inspector and the compiler. Without this contract the
 * "no font could be found" error leaks out of `inspect` and disappears
 * on `render` — a confusing diagnostic.
 *
 * The producer's `?Closure $stackFactory` seam lets the test stub
 * `TypstWorldFactory::build()` so the ext-typst-dependent `Compiler`
 * and `Inspector` objects never need to be instantiated; their
 * fake counterparts throw before `renderPdf/Png/Svg` need a real
 * `Typst\Document`.
 */
beforeEach(function () {
    $this->paths = new Paths(sys_get_temp_dir());
});

it('passes the world-factory-wrapped source (prelude + user bytes) into the inspector', function (): void {
    $inspector = new class {
        public ?string $lastSource = null;

        public function inspectString(string $source): object
        {
            $this->lastSource = $source;
            return new class {
                public function success(): bool
                {
                    return true;
                }
                public function hasErrors(): bool
                {
                    return false;
                }
                public function errors(): array
                {
                    return [];
                }
                public function warnings(): array
                {
                    return [];
                }
            };
        }
    };
    $compiler = new class {
        public function compileString(string $source): object
        {
            // Document is final + ext-typst-defined; throw to short-
            // circuit before renderPdf/Png/Svg need a real instance.
            throw new RuntimeException('test bypass');
        }
    };
    $stack = static fn(?int $principalId): array => [
        'world' => new stdClass(),
        'compiler' => $compiler,
        'inspector' => $inspector,
    ];

    $producer = new TypstRenderProducer(new TypstWorldFactory($this->paths), stackFactory: $stack);

    $asset = new MediaAsset();
    $asset->id = 'wrap-inspector';
    $asset->mime_type = 'text/x-typst';
    $asset->storage_mode = 'data_url';
    $asset->payload = '= Hello';

    expect(fn() => $producer->produce($asset, 'pdf', []))
        ->toThrow(TypstCompilationException::class);

    expect($inspector->lastSource)->not->toBeNull();
    expect($inspector->lastSource)->toStartWith('#set text(font:');
    expect($inspector->lastSource)->toContain('"Inter"');
    expect(substr($inspector->lastSource, -strlen('= Hello')))->toBe('= Hello');
});

it('passes the world-factory-wrapped source (prelude + user bytes) into the compiler', function (): void {
    $inspector = new class {
        public function inspectString(string $source): object
        {
            return new class {
                public function success(): bool
                {
                    return true;
                }
                public function hasErrors(): bool
                {
                    return false;
                }
                public function errors(): array
                {
                    return [];
                }
                public function warnings(): array
                {
                    return [];
                }
            };
        }
    };
    $compiler = new class {
        public ?string $lastSource = null;

        public function compileString(string $source): object
        {
            $this->lastSource = $source;
            throw new RuntimeException('test bypass');
        }
    };
    $stack = static fn(?int $principalId): array => [
        'world' => new stdClass(),
        'compiler' => $compiler,
        'inspector' => $inspector,
    ];

    $producer = new TypstRenderProducer(new TypstWorldFactory($this->paths), stackFactory: $stack);

    $asset = new MediaAsset();
    $asset->id = 'wrap-compiler';
    $asset->mime_type = 'text/x-typst';
    $asset->storage_mode = 'data_url';
    $asset->payload = '= Hello';

    expect(fn() => $producer->produce($asset, 'pdf', []))
        ->toThrow(TypstCompilationException::class);

    expect($compiler->lastSource)->not->toBeNull();
    expect($compiler->lastSource)->toStartWith('#set text(font:');
    expect($compiler->lastSource)->toContain('"Inter"');
    expect(substr($compiler->lastSource, -strlen('= Hello')))->toBe('= Hello');
});

it('preserves the inspector error diagnostic when wrapped source fails the inspection', function (): void {
    // Pins that when the inspector surfaces an error after the wrap,
    // the producer short-circuits to `TypstCompilationException`
    // without ever reaching the compiler — `compileString()` would
    // see the wrapped source too, but the inspection branch fires
    // first so it never gets called.
    $inspector = new class {
        public function inspectString(string $source): object
        {
            return new class {
                public function success(): bool
                {
                    return false;
                }
                public function hasErrors(): bool
                {
                    return true;
                }
                public function errors(): array
                {
                    return [];
                }
                public function warnings(): array
                {
                    return [];
                }
            };
        }
    };
    $compiler = new class {
        public int $compileCalls = 0;

        public function compileString(string $source): object
        {
            $this->compileCalls++;
            throw new RuntimeException('test bypass');
        }
    };
    $stack = static fn(?int $principalId): array => [
        'world' => new stdClass(),
        'compiler' => $compiler,
        'inspector' => $inspector,
    ];

    $producer = new TypstRenderProducer(new TypstWorldFactory($this->paths), stackFactory: $stack);

    $asset = new MediaAsset();
    $asset->id = 'wrap-inspector-error';
    $asset->mime_type = 'text/x-typst';
    $asset->storage_mode = 'data_url';
    $asset->payload = '= Hello';

    expect(fn() => $producer->produce($asset, 'pdf', []))
        ->toThrow(TypstCompilationException::class);

    expect($compiler->compileCalls)->toBe(0);
});
