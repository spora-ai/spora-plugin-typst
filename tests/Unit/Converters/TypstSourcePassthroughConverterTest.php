<?php

declare(strict_types=1);

use Spora\Plugins\Typst\Converters\TypstSourcePassthroughConverter;
use Spora\Services\MediaArchive\MediaConverterInterface;

it('declares the text/x-typst MIME and the typ extension', function (): void {
    $converter = new TypstSourcePassthroughConverter();

    expect($converter->supportedMimeTypes())->toBe(['text/x-typst']);
    expect($converter->supportedExtensions())->toBe(['typ']);
});

it('is wired as a MediaConverterInterface so the registry will pick it up', function (): void {
    expect(new TypstSourcePassthroughConverter())->toBeInstanceOf(MediaConverterInterface::class);
});

it('returns the bytes as-is for toMarkdown (Typst source is plain UTF-8 text)', function (): void {
    // trim() is edge-only — inner content passes through verbatim.
    $bytes = "= Hello, world!\n\nThis is a Typst document.\n";
    $converter = new TypstSourcePassthroughConverter();

    expect($converter->toMarkdown($bytes, 'text/x-typst', 'hello.typ'))
        ->toBe("= Hello, world!\n\nThis is a Typst document.");
});

it('trims trailing whitespace from toMarkdown so the agent sees clean content', function (): void {
    $bytes = "= Hello\n   \n\n";
    $converter = new TypstSourcePassthroughConverter();

    expect($converter->toMarkdown($bytes, 'text/x-typst'))->toBe("= Hello");
});

it('toMarkdown ignores the filename argument (the bytes are the content)', function (): void {
    $converter = new TypstSourcePassthroughConverter();

    $a = $converter->toMarkdown("= A\n", 'text/x-typst', 'a.typ');
    $b = $converter->toMarkdown("= A\n", 'text/x-typst', 'totally-different-name.typ');

    expect($a)->toBe($b);
});
