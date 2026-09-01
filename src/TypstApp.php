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
        // `file-type-2` is in the bundled icon palette (see
        // spora-core/docs/07_plugins.md § Bundled icons).
        return 'file-type-2';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
