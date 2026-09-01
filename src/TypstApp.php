<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Typst plugin.
 *
 * The host loads the frontend bundle from the plugin slug and entry
 * filename. The entry must match the frontend bundle's
 * `build.lib.fileName()` value (default: `main.js`).
 */
final class TypstApp implements VueAppInterface
{
    public function name(): string
    {
        return 'typst';
    }

    public function displayName(): string
    {
        return 'Typst';
    }

    public function description(): string
    {
        return 'Compile Typst source to PDF / PNG / SVG. Manage plugin fonts and per-principal example templates.';
    }

    public function icon(): string
    {
        // `pilcrow` — the typographic paragraph mark (¶). The
        // canonical "this is about typography/typesetting" signal;
        // matches the spirit of memories/brain (semantic glyph)
        // and media-archive/image (content-type glyph).
        return 'pilcrow';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
