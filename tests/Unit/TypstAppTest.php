<?php

declare(strict_types=1);

use Spora\Plugins\Typst\TypstApp;

it('returns a bundled icon name rather than raw SVG path data', function () {
    // The host's <Icon> resolves names against its bundled registry
    // and falls back to `puzzle` for anything it doesn't know. Raw
    // `d` data is only honoured when it starts with `M<digit>` or
    // `m<-?digit>`, so the registry name is the durable option. No
    // test here can observe a host-side registry drop — pinning the
    // name is what surfaces that as a diff here instead of a silent
    // puzzle glyph in the admin UI.
    expect((new TypstApp())->icon())->toBe('pilcrow');
});
