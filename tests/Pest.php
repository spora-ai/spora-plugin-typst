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

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery as M;
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

uses()
    ->beforeEach(function () {
        Database::resetBootState();
        $tmpDb = sys_get_temp_dir() . '/spora-plugin-typst-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => $tmpDb]);
        $db->bootDatabaseConnectionOnly();

        // Install the full core migration set (the plugin owns no
        // tables, but every test that touches MediaAsset /
        // media_derivatives needs the full schema in place).
        $installer = new DatabaseSchemaInstaller(null, null, null);
        $installer->install();

        Capsule::connection()->beginTransaction();
    })
    ->afterEach(function () {
        if (Capsule::connection()->transactionLevel() > 0) {
            Capsule::connection()->rollBack();
        }
        Database::resetBootState();
        // Clear the discovery registry so test order doesn't
        // affect what `all()` reports.
        MediaDerivativeProducerDiscovery::reset();
        M::close();
    })
    ->in(__DIR__);
