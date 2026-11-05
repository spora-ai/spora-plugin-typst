<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Mirrors spora-plugin-media-archive's pattern: define BASE_PATH, then
| hand-roll a `uses(...)` block that installs the full core migration set
| into a per-process in-memory SQLite and rolls back each test in
| afterEach for isolation.
|
| Tests that depend on ext-typst (the producer's `produce()` and the
| tool's full pipeline) skip themselves when the extension is missing —
| CI on a vanilla ubuntu runner doesn't ship ext-typst, and the rest of
| the suite (resource store, path resolver, producer contracts,
| controller routing) should still run.
|
*/

use Delight\Auth\Auth as DelightAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery as M;
use Spora\Auth\AuthService;
use Spora\Core\Config;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

// CI on a vanilla ubuntu runner doesn't ship ext-typst, so any
// test that imports `Typst\…` (e.g. the diagnostic formatter's
// Severity enum) would fail at parse time before the test's own
// `extension_loaded()` check could run. Load the stubs from
// `stubs/typst.php` only when the extension is missing so the
// test files can `use Typst\…` unconditionally. When ext-typst
// is loaded (local dev), the real classes take precedence —
// PHP's class table is populated by the C extension at startup
// before this bootstrap runs.
if (!extension_loaded('typst') && is_file(BASE_PATH . '/stubs/typst.php')) {
    require_once BASE_PATH . '/stubs/typst.php';
}

set_error_handler(static function (...$handlerArgs): bool {
    [$errno, , $errfile] = $handlerArgs;

    if ($errno === E_DEPRECATED && str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)) {
        return true;
    }

    return false;
}, E_DEPRECATED);

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use
 * AuthService. Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): AuthService
{
    $pdo  = Capsule::connection()->getPdo();
    $auth = new DelightAuth($pdo, null, null, false /* throttling off */);

    return new AuthService($auth);
}

/**
 * Simulate a logged-in session by populating the PHP session
 * superglobal the same way delight-im/auth does internally.
 */
function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[DelightAuth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[DelightAuth::SESSION_FIELD_USER_ID]   = $userId;
    $_SESSION[DelightAuth::SESSION_FIELD_EMAIL]     = $email;
    $_SESSION[DelightAuth::SESSION_FIELD_USERNAME]  = null;
}

function clearSession(): void
{
    $_SESSION = [];
}

/**
 * Point the plugin's storage at a directory this test owns.
 *
 * The plugin resolves its storage through `Paths::storage()`, which
 * honours `SPORA_STORAGE_DIR` on every call — the one knob that
 * reaches the `Paths` instances the plugin builds for itself (the
 * tools' `paths()`, the controllers' `paths()`, the producer's
 * local-asset reader) and that a test cannot pass in.
 *
 * Without it every test in the suite resolves one shared tree: each
 * parallel worker has its own database, so `ensureUserPrincipal()`
 * hands out the same small ids in every worker, and the cleanup of
 * one test deletes the files of another.
 *
 * @return string the root the plugin resolves to from now on
 */
function isolatePluginStorage(): string
{
    $state = $GLOBALS['__spora_typst_storage'] ?? null;
    if (!is_array($state)) {
        $state = ['previous' => getenv('SPORA_STORAGE_DIR'), 'roots' => []];
    }

    $root = sys_get_temp_dir() . '/spora-plugin-typst-storage-' . getmypid() . '-' . bin2hex(random_bytes(8));

    $roots = $state['roots'] ?? [];
    if (!is_array($roots)) {
        $roots = [];
    }
    $roots[] = $root;

    $state['roots'] = $roots;
    $GLOBALS['__spora_typst_storage'] = $state;

    putenv("SPORA_STORAGE_DIR={$root}");
    $_ENV['SPORA_STORAGE_DIR']    = $root;
    $_SERVER['SPORA_STORAGE_DIR'] = $root;

    return $root;
}

/**
 * Undo {@see isolatePluginStorage()}: restore the previous override and
 * remove the trees the test wrote into. The trees are the test's own,
 * so nothing outside them is ever deleted.
 */
function releasePluginStorage(): void
{
    $state = $GLOBALS['__spora_typst_storage'] ?? null;
    unset($GLOBALS['__spora_typst_storage']);

    if (!is_array($state)) {
        return;
    }

    $previous = $state['previous'] ?? false;
    if (is_string($previous) && $previous !== '') {
        putenv("SPORA_STORAGE_DIR={$previous}");
        $_ENV['SPORA_STORAGE_DIR']    = $previous;
        $_SERVER['SPORA_STORAGE_DIR'] = $previous;
    } else {
        putenv('SPORA_STORAGE_DIR');
        unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
    }

    $roots = $state['roots'] ?? [];
    if (!is_array($roots)) {
        return;
    }
    foreach ($roots as $root) {
        if (is_string($root)) {
            removeDirectoryTree($root);
        }
    }
}

/**
 * Delete a directory and everything under it. The suite only ever
 * removes a tree it created itself.
 */
function removeDirectoryTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
}

uses()
    ->beforeEach(function () {
        Database::resetBootState();
        $tmpDb = sys_get_temp_dir() . '/spora-plugin-typst-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database(new Config(['SPORA_DB_DRIVER' => 'sqlite', 'SPORA_DB_PATH' => $tmpDb]));
        $db->bootDatabaseConnectionOnly();

        // Install the full core migration set (the plugin owns no
        // tables, but every test that touches MediaAsset /
        // media_derivatives / users needs the full schema in place).
        $installer = new DatabaseSchemaInstaller(null, null, null);
        $installer->install();

        Capsule::connection()->beginTransaction();
    })
    ->afterEach(function () {
        if (Capsule::connection()->transactionLevel() > 0) {
            Capsule::connection()->rollBack();
        }
        Database::resetBootState();
        clearSession();
        MediaDerivativeProducerDiscovery::reset();
        M::close();
    })
    ->in(__DIR__);
