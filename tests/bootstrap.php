<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Plugin-local Bootstrap
|--------------------------------------------------------------------------
|
| Mirrors spora-plugin-media-archive's bootstrap: BASE_PATH constant
| (so plugin code that calls `dirname(__DIR__, 3)`-style helpers resolves
| correctly when `ImageDerivativeProducer` and friends walk up to the
| spora-core install), in-memory SQLite with the full core migration
| set installed via `DatabaseSchemaInstaller`, and a per-test transaction
| rolled back in afterEach for isolation.
|
| The plugin doesn't own any tables — every table it touches
| (`media_assets`, `media_derivatives`, `users`, `agents`, `principals`)
| lives in spora-core. `DatabaseSchemaInstaller::install()` is
| idempotent on the `schema_versions` table, so successive test runs
| against the same connection short-circuit on the second beforeEach.
|
| ext-typst is **optional** in CI: tests that actually compile Typst
| source skip themselves when the extension is missing. The producer's
| `pluginSlug()` / `supportedSourceFormats()` round-trip tests always
| run because they don't touch the extension.
|
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

set_error_handler(static function (...$handlerArgs): bool {
    [$errno, , $errfile] = $handlerArgs;

    if ($errno === E_DEPRECATED && str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)) {
        return true;
    }

    return false;
}, E_DEPRECATED);
