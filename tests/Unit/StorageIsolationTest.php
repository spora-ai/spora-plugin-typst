<?php

declare(strict_types=1);

use Spora\Core\Paths;

/**
 * The plugin resolves its storage through `Paths::storage()`, and every
 * test in the suite resolves the same tree unless it says otherwise:
 * each parallel worker has its own database, so `ensureUserPrincipal()`
 * hands out the same small principal ids in every worker. Two tests
 * therefore share one `<storage>/typst/<principal>/` directory — and
 * the cleanup of one deletes the files of the other. That is the flake
 * that made the compile tool test fail at random.
 *
 * These tests pin the isolation: each test that touches the plugin's
 * storage tree points `SPORA_STORAGE_DIR` at a directory of its own.
 */
beforeEach(function () {
    $this->root = isolatePluginStorage();
});

afterEach(function () {
    releasePluginStorage();
});

it('resolves the plugin storage outside the repository tree', function () {
    expect((new Paths(BASE_PATH))->storage())->toBe($this->root);
    expect($this->root)->not->toBe(BASE_PATH . '/storage');
});

it('keeps one test\'s files out of another test\'s cleanup', function () {
    // Two workers, two roots. The writer writes into the root the
    // plugin resolves; the wiper removes the principal directory of
    // the root it resolves. Different roots, so the wipe cannot reach
    // the writer's file — which is the whole point of the isolation.
    $writerRoot = (new Paths(BASE_PATH))->storage();
    $wiperRoot  = isolatePluginStorage();

    $templateDir = $writerRoot . '/typst/1/templates';
    mkdir($templateDir, 0o755, true);
    file_put_contents($templateDir . '/webinar-single-post.typ', "= Webinar promo\n");

    removeDirectoryTree($wiperRoot . '/typst/1');

    expect(is_file($templateDir . '/webinar-single-post.typ'))->toBeTrue();
});

it('isolates the storage root in every test file that resolves it through BASE_PATH', function () {
    // Static guard: a test file that lets the plugin resolve its
    // storage through `BASE_PATH` shares one tree with the whole
    // suite unless it points `SPORA_STORAGE_DIR` at a directory of
    // its own.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(BASE_PATH . '/tests', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        $resolvesSharedStorage = str_contains($source, 'new Paths(BASE_PATH)')
            || str_contains($source, "BASE_PATH . '/storage");
        if (!$resolvesSharedStorage) {
            continue;
        }

        if (!str_contains($source, 'isolatePluginStorage()')) {
            $offenders[] = str_replace(BASE_PATH . '/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
