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
        // The typographic paragraph mark (¶) — Lucide's `Pilcrow`.
        // The host's bundled-icon registry doesn't include
        // `pilcrow`, so anything unknown there falls back to
        // `puzzle` (the puzzle piece). Ship a raw `d` string
        // instead — the host's <Icon> component accepts anything
        // starting with a path command letter as a single-path
        // icon, so we don't need to coordinate with the Spora
        // frontend to add the name.
        return 'M13 4v16M17 4v16M19 4H9.5a4.5 4.5 0 0 0 0 9H13';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
