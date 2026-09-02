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
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;

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

uses()
    ->beforeEach(function () {
        Database::resetBootState();
        $tmpDb = sys_get_temp_dir() . '/spora-plugin-typst-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => $tmpDb]);
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
