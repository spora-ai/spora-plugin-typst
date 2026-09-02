<?php

declare(strict_types=1);

use Spora\Plugins\Typst\TypstApp;

it('returns a raw SVG path for its icon (avoids the host puzzle fallback)', function () {
    $app = new TypstApp();

    // The host's <Icon> component falls back to the puzzle
    // glyph for any name it doesn't know. Ship a raw `d`
    // string instead so the host's "starts with a path
    // command letter" branch fires and renders the
    // typographic paragraph mark (¶) without a Spora
    // frontend coordination round-trip.
    expect($app->icon())->toStartWith('M');
});

it('uses Lucide Pilcrow path data so the paragraph mark renders correctly', function () {
    $app = new TypstApp();
    $path = $app->icon();

    // Three subpaths: two vertical stems (M13 v16, M17 v16) and
    // the typographic bowl (H9.5a4.5 4.5 0 0 0 0 9H13). Without
    // the bowl subpath the icon would render as two parallel
    // vertical strokes instead of the ¶.
    expect($path)->toContain('M13 4v16')
        ->and($path)->toContain('M17 4v16')
        ->and($path)->toContain('a4.5 4.5 0 0 0 0 9');
});
