<?php

declare(strict_types=1);

use Spora\Plugins\Typst\TypstApp;

it('returns a bundled icon name rather than raw SVG path data', function () {
    // Pins the choice, not the host: a registry drop upstream would still pass here.
    expect((new TypstApp())->icon())->toBe('pilcrow');
});
