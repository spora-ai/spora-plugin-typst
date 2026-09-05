<?php

declare(strict_types=1);

use Spora\Plugins\Typst\Exceptions\TypstInvalidArgumentException;
use Spora\Plugins\Typst\Services\TypstFilename;

/**
 * Pins down the shared filename rule used by
 * {@see Spora\Plugins\Typst\Tools\TypstCompileTool},
 * {@see Spora\Plugins\Typst\Http\TypstCompileController}, and
 * {@see Spora\Plugins\Typst\Http\TypstPlaygroundSourceController}.
 *
 * The helper is the one definition of "valid .typ basename" — a
 * regression here breaks three endpoints at once, which is why the
 * coverage is exhaustive: empty inputs, type errors, the regex
 * deny-list (control bytes + path separators), the length cap, the
 * `.typ` auto-append, and the default-fallback behaviour.
 */
const TYPST_FILENAME_DEFAULT = 'playground.typ';

it('returns the default when the raw input is null', function (): void {
    expect(TypstFilename::sanitise(null, TYPST_FILENAME_DEFAULT))->toBe(TYPST_FILENAME_DEFAULT);
});

it('returns the default when the raw input is an empty string', function (): void {
    expect(TypstFilename::sanitise('', TYPST_FILENAME_DEFAULT))->toBe(TYPST_FILENAME_DEFAULT);
});

it('returns the default when the raw input is whitespace only', function (): void {
    expect(TypstFilename::sanitise("   \t\n", TYPST_FILENAME_DEFAULT))->toBe(TYPST_FILENAME_DEFAULT);
});

it('rejects a non-string input', function (): void {
    TypstFilename::sanitise(42, TYPST_FILENAME_DEFAULT);
})->throws(TypstInvalidArgumentException::class, 'must be a string');

it('trims surrounding whitespace on a valid basename', function (): void {
    expect(TypstFilename::sanitise('  letter.typ  ', TYPST_FILENAME_DEFAULT))->toBe('letter.typ');
});

it('auto-appends .typ when the basename lacks the suffix', function (): void {
    expect(TypstFilename::sanitise('letter', TYPST_FILENAME_DEFAULT))->toBe('letter.typ');
    expect(TypstFilename::sanitise('cover-letter', TYPST_FILENAME_DEFAULT))->toBe('cover-letter.typ');
});

it('rejects names containing a forward slash', function (): void {
    TypstFilename::sanitise('../escape.typ', TYPST_FILENAME_DEFAULT);
})->throws(TypstInvalidArgumentException::class, 'illegal characters');

it('rejects names containing a backslash', function (): void {
    TypstFilename::sanitise('foo\\bar.typ', TYPST_FILENAME_DEFAULT);
})->throws(TypstInvalidArgumentException::class, 'illegal characters');

it('rejects names containing control bytes', function (): void {
    TypstFilename::sanitise("foo\nbar.typ", TYPST_FILENAME_DEFAULT);
})->throws(TypstInvalidArgumentException::class, 'illegal characters');

it('rejects names containing a NUL byte', function (): void {
    TypstFilename::sanitise("foo\0bar.typ", TYPST_FILENAME_DEFAULT);
})->throws(TypstInvalidArgumentException::class, 'illegal characters');

it('rejects names longer than 128 characters', function (): void {
    $name = str_repeat('a', 129);
    TypstFilename::sanitise($name, TYPST_FILENAME_DEFAULT);
})->throws(TypstInvalidArgumentException::class, 'too long');

it('accepts names whose stem is exactly 128 characters long', function (): void {
    $stem = str_repeat('a', 128);
    expect(TypstFilename::sanitise($stem, TYPST_FILENAME_DEFAULT))->toBe($stem . '.typ');
});

it('accepts a 128-char name including the .typ suffix', function (): void {
    $name = str_repeat('a', 124) . '.typ';
    expect(TypstFilename::sanitise($name, TYPST_FILENAME_DEFAULT))->toBe($name);
});

it('uses a caller-supplied default when the input is empty', function (): void {
    expect(TypstFilename::sanitise('', 'untitled.typ'))->toBe('untitled.typ');
});
