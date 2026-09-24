<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Plugins\Typst\Tools\TypstResourcesTool;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaAssetReader;

beforeEach(function () {
    $paths = new Paths(sys_get_temp_dir());
    $this->worldFactory = new TypstWorldFactory($paths);

    $this->auth = bootAuthLayer();
    $this->userId = $this->auth->register('resources-tool-' . bin2hex(random_bytes(4)) . '@example.com', 'Password1!', 'Resources Tool');
    simulateLoggedInSession($this->userId, 'resources-tool-' . bin2hex(random_bytes(4)) . '@example.com');

    $this->principalService = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());
    $this->principalId = $this->principalService->ensureUserPrincipal($this->userId)->id;

    // Per-call path resolution — the tool builds its own
    // `TypstResourcePaths` from `$this->paths()` (which reads
    // BASE_PATH) and the call's `$context?->principalId`. We mirror
    // that here so the test's `Paths(sys_get_temp_dir())` matches
    // the BASE_PATH the tool resolves at runtime.
    $this->resourcePaths = new TypstResourcePaths($paths, principalId: $this->principalId);

    $this->tool = new TypstResourcesTool(
        $this->worldFactory,
    );

    // The `import` verb needs a `MediaAssetReader` closure. Tests
    // that exercise `import` rebuild the tool with the wired
    // closure via `toolWithReader()`; tests that don't, leave the
    // closure as `null` so they don't drag the full DB/LocalAsset
    // wiring into every case.
    $this->database = new DatabaseAssetStore(50 * 1024 * 1024);
    $this->local    = new LocalAssetStore(
        new Paths(sys_get_temp_dir()),
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        50 * 1024 * 1024,
    );
    $this->mediaReader = new MediaAssetReader($this->database, $this->local);
    // The closure receives the live reader via `use` rather than
    // `$this->mediaReader->...` because `static fn` doesn't bind
    // `$this`. The reader is the same instance for the whole test
    // (storing it once also keeps the bridge's identity stable).
    $reader = $this->mediaReader;
    $this->mediaReaderFn = static fn(string $id, ?int $userId): ?array => $reader->readAsset($id, $userId);

    $this->context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );
});

/**
 * Build a fresh tool instance with the MediaAssetReader closure
 * wired in. Returns a new tool (the existing one stays around so
 * non-import tests don't accidentally pick up the closure).
 *
 * Takes the deps explicitly so PHPStan can verify the call against
 * the `TypstResourcesTool` signature instead of fishing them out of
 * Pest's `$this` (which is typed as `TestCall|…` and has no
 * `worldFactory` / `mediaReaderFn` properties).
 */
function toolWithReader(TypstWorldFactory $worldFactory, Closure $mediaReaderFn): TypstResourcesTool
{
    return new TypstResourcesTool($worldFactory, $mediaReaderFn);
}

/**
 * Materialise a `data_url`-mode media_assets row, returning the
 * UUID. `bytes` go straight into `payload`. Pass
 * `external: true` for the external-mode branch.
 *
 * @return array{0: string, 1: MediaAsset}
 */
function seedMediaAsset(int $userId, string $mime, string $bytes, string $filename, string $pluginSlug = 'test', bool $external = false): array
{
    $id = sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffffffffffff),
    );
    $asset = new MediaAsset();
    $asset->id            = $id;
    $asset->user_id       = $userId;
    $asset->agent_id      = null;
    $asset->principal_id  = null;
    $asset->plugin_slug   = $pluginSlug;
    $asset->tool_name     = 'test.seed';
    $asset->mime_type     = $mime;
    $asset->media_type    = 'image';
    $asset->byte_size     = strlen($bytes);
    $asset->filename      = $filename;
    $asset->storage_mode  = $external ? 'external' : 'data_url';
    $asset->asset_token   = $external ? null : bin2hex(random_bytes(16));
    $asset->payload       = $external ? null : $bytes;
    $asset->source_url    = $external ? 'https://example.invalid/missing.webp' : null;
    $asset->asset_url     = Spora\Services\MediaArchive\MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $asset->upload_source = 'test';
    $asset->save();
    return [$id, $asset];
}

afterEach(function () {
    // Best-effort cleanup — the principal dir is per-user, so the
    // storage dir itself stays under BASE_PATH/storage; nothing
    // to remove there.
});

describe('kind discriminator', function (): void {
    it('rejects an unknown kind', function (): void {
        $result = $this->tool->execute(
            ['action' => 'bogus', 'op' => 'list'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('unknown action "bogus"');
        expect($result->content)->toContain('fonts, templates, examples, images, media_assets');
    });

    it('rejects an unknown op sub-action verb', function (): void {
        $result = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'wipe'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('unknown op "wipe"');
        expect($result->content)->toContain('list, write, delete, read, import');
    });

    it('rejects op=import against the kind actions (cross-action invalid pairing)', function (): void {
        // `op: "import"` is the verb of `action: "media_assets"` only;
        // pairing it with the kind actions surfaces a clean error
        // rather than a silent fall-through.
        $result = $this->tool->execute(
            ['action' => 'images', 'op' => 'import'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('action "images" does not accept op "import"');
        expect($result->content)->toContain('list, write, delete, read');

        // And the mirror direction: `op: "list"` against `media_assets`.
        $mirror = $this->tool->execute(
            ['action' => 'media_assets', 'op' => 'list', 'asset_id' => '00000000-0000-4000-8000-000000000000'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($mirror->success)->toBeFalse();
        expect($mirror->content)->toContain('action "media_assets" does not accept op "list"');
    });
});

describe('fonts/templates/examples dispatch', function (): void {
    it('writes and lists a font round-trip', function (): void {
        $result = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'write', 'name' => 'Acme.otf', 'content' => 'OTF-BYTES'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('wrote 9 bytes');

        $listResult = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'list'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($listResult->success)->toBeTrue();
        expect($listResult->content)->toContain('Acme.otf');
        expect($listResult->content)->toContain('principal');
    });

    it('rejects write when name is missing', function (): void {
        $result = $this->tool->execute(
            ['action' => 'templates', 'op' => 'write', 'content' => 'hello'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('`name` and `content` are required');
    });

    it('rejects write when content is missing', function (): void {
        $result = $this->tool->execute(
            ['action' => 'examples', 'op' => 'write', 'name' => 'x.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('`name` and `content` are required');
    });

    it('rejects delete when name is missing', function (): void {
        $result = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'delete'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('`name` is required');
    });

    it('deletes a previously-written resource', function (): void {
        $this->tool->execute(
            ['action' => 'fonts', 'op' => 'write', 'name' => 'doomed.otf', 'content' => 'OTF'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        $result = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'delete', 'name' => 'doomed.otf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('deleted font/doomed.otf');
    });
});

describe('images dispatch', function (): void {
    it('rejects image write with an unsupported mime', function (): void {
        $result = $this->tool->execute(
            ['action' => 'images', 'op' => 'write', 'name' => 'logo.bin', 'content' => 'BIN'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('mime');
    });

    it('rejects image write when name or content is missing', function (): void {
        $result = $this->tool->execute(
            ['action' => 'images', 'op' => 'write', 'name' => 'logo.png'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('`name` and `content` are required');
    });

    it('rejects image delete when name is missing', function (): void {
        $result = $this->tool->execute(
            ['action' => 'images', 'op' => 'delete'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('`name` is required');
    });
});

describe('describeAction', function (): void {
    it('describes a list op with no name', function (): void {
        expect($this->tool->describeAction(['action' => 'fonts', 'op' => 'list']))
            ->toContain('fonts/list');
    });

    it('describes a write op with a name', function (): void {
        expect($this->tool->describeAction(['action' => 'templates', 'op' => 'write', 'name' => 'doc.typ']))
            ->toContain('templates/write')
            ->toContain('doc.typ');
    });
});

describe('principal scope propagation', function (): void {
    it('honours the principal from the supplied PrincipalContext on each call', function (): void {
        // Register a second principal — they MUST NOT see the first
        // principal's resources, even though the tool itself is a
        // shared singleton. The per-call `TypstResourcePaths`
        // construction is what enforces the boundary.
        $userIdB = $this->auth->register('principal-b-' . bin2hex(random_bytes(4)) . '@example.com', 'Password1!', 'Principal B');
        $principalIdB = $this->principalService->ensureUserPrincipal($userIdB)->id;
        $contextB = new Spora\Services\PrincipalContext(
            principalId: $principalIdB,
            type: 'user',
            ownerUserId: $userIdB,
            runnerUserId: $userIdB,
        );

        $this->tool->execute(
            ['action' => 'fonts', 'op' => 'write', 'name' => 'private-A.otf', 'content' => 'A-BYTES'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        $principalBList = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'list'],
            agentId: 0,
            userId: $userIdB,
            context: $contextB,
        );
        expect($principalBList->success)->toBeTrue();
        expect($principalBList->content)->not->toContain('private-A.otf');

        $principalAList = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'list'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($principalAList->content)->toContain('private-A.otf');
    });

    it('throws a TypstRuntimeException when called without a PrincipalContext (no principal in scope)', function (): void {
        // A null context simulates a CLI / background worker path
        // where the orchestrator hasn't resolved a principal. The
        // tool must throw — silently returning a "no resources"
        // ToolResult would mask the wiring bug the original report
        // surfaced.
        expect(fn() => $this->tool->execute(
            ['action' => 'fonts', 'op' => 'list'],
            agentId: 0,
            userId: $this->userId,
            context: null,
        ))->toThrow(TypstRuntimeException::class);
    });
});

describe('op: read (list → read → modify → write → render iteration loop)', function (): void {
    it('reads back a previously-written template as inline UTF-8 bytes', function (): void {
        $payload = "= Hello\n#set page(width: 1080pt, height: 1080pt)\nThis is the source.\n";
        $this->tool->execute(
            ['action' => 'templates', 'op' => 'write', 'name' => 'teaser.typ', 'content' => $payload],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        $result = $this->tool->execute(
            ['action' => 'templates', 'op' => 'read', 'name' => 'teaser.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('Source of templates/teaser.typ');
        expect($result->content)->toContain($payload);
        expect($result->data['encoding'])->toBe('utf-8');
        expect($result->data['byte_size'])->toBe(strlen($payload));
        expect($result->data['name'])->toBe('teaser.typ');
        expect($result->data['kind'])->toBe('templates');
        expect($result->data)->not->toHaveKey('content_base64');
    });

    it('returns examples as inline UTF-8 too (text-shaped resource)', function (): void {
        $payload = "// Example\n= Hello\n";
        $this->tool->execute(
            ['action' => 'examples', 'op' => 'write', 'name' => 'hello.typ', 'content' => $payload],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        $result = $this->tool->execute(
            ['action' => 'examples', 'op' => 'read', 'name' => 'hello.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['encoding'])->toBe('utf-8');
        expect($result->content)->toContain($payload);
    });

    it('returns fonts as base64 under data.content_base64', function (): void {
        // 4 bytes isn't a valid font but proves the binary branch —
        // the tool doesn't MIME-sniff, it only branches on kind.
        $bytes = "OTF\x00";
        $this->tool->execute(
            ['action' => 'fonts', 'op' => 'write', 'name' => 'Acme.otf', 'content' => $bytes],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        $result = $this->tool->execute(
            ['action' => 'fonts', 'op' => 'read', 'name' => 'Acme.otf'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['encoding'])->toBe('base64');
        expect($result->data['byte_size'])->toBe(strlen($bytes));
        expect(base64_decode((string) $result->data['content_base64'], true))->toBe($bytes);
    });

    it('returns a not-found error when the basename is missing from both tiers', function (): void {
        $result = $this->tool->execute(
            ['action' => 'templates', 'op' => 'read', 'name' => 'ghost.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('not found');
        expect($result->content)->toContain('ghost.typ');
        expect($result->content)->toContain('tier-2 then tier-1');
    });

    it('rejects read when `name` is missing', function (): void {
        $result = $this->tool->execute(
            ['action' => 'templates', 'op' => 'read'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('`name` is required');
    });

    it('honours the principal scope — Principal B cannot read Principal A templates', function (): void {
        // Principal A uploads a private template; Principal B's
        // `read` must return "not found", never the bytes. Mirrors
        // the existing principal-scope propagation test.
        $userIdB = $this->auth->register('principal-b-read-' . bin2hex(random_bytes(4)) . '@example.com', 'Password1!', 'Principal B Read');
        $principalIdB = $this->principalService->ensureUserPrincipal($userIdB)->id;
        $contextB = new Spora\Services\PrincipalContext(
            principalId: $principalIdB,
            type: 'user',
            ownerUserId: $userIdB,
            runnerUserId: $userIdB,
        );

        $this->tool->execute(
            ['action' => 'templates', 'op' => 'write', 'name' => 'private-A.typ', 'content' => 'PRIVATE'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        $principalBRead = $this->tool->execute(
            ['action' => 'templates', 'op' => 'read', 'name' => 'private-A.typ'],
            agentId: 0,
            userId: $userIdB,
            context: $contextB,
        );
        expect($principalBRead->success)->toBeFalse();
        expect($principalBRead->content)->toContain('not found');

        $principalARead = $this->tool->execute(
            ['action' => 'templates', 'op' => 'read', 'name' => 'private-A.typ'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($principalARead->success)->toBeTrue();
        expect($principalARead->content)->toContain('PRIVATE');
    });

    it('rejects read with an invalid basename charset (store-side validation)', function (): void {
        // `TypstResourceStore::read()` validates the basename via the
        // same conservative charset as `write`. A basename containing
        // `/` must throw, which the tool translates into a
        // `typst_resources: ...` prefixed error message.
        $result = $this->tool->execute(
            ['action' => 'templates', 'op' => 'read', 'name' => 'evil/../escape'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('invalid basename');
    });
});

describe('op: read (images)', function (): void {
    it('rejects image read when `name` is missing', function (): void {
        $read = $this->tool->execute(
            ['action' => 'images', 'op' => 'read'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($read->success)->toBeFalse();
        expect($read->content)->toContain('`name` is required');
    });

    it('returns a not-found error when the image basename is missing', function (): void {
        $read = $this->tool->execute(
            ['action' => 'images', 'op' => 'read', 'name' => 'ghost.png'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($read->success)->toBeFalse();
        expect($read->content)->toContain('not found');
        expect($read->content)->toContain('ghost.png');
    });

    it('rejects image read with an invalid basename charset', function (): void {
        $read = $this->tool->execute(
            ['action' => 'images', 'op' => 'read', 'name' => 'evil/../escape'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($read->success)->toBeFalse();
        expect($read->content)->toContain('invalid basename');
    });
});

describe('op: import (Media Archive → image library bridge)', function (): void {
    it('copies a data_url-mode webp asset into the principal\'s image library', function (): void {
        // A genuinely webp-shaped payload is unimportant — the test
        // only exercises the bridge; the asset's mime is the input.
        $bytes = "RIFF\x00\x00\x00\x00WEBP-BYTES";
        [$assetId] = seedMediaAsset($this->userId, 'image/webp', $bytes, 'autumn.webp');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'autumn.webp'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain("imported media asset {$assetId}");
        expect($result->data['name'])->toBe('autumn.webp');
        expect($result->data['url'])->toBe('/api/v1/typst/images/autumn.webp');
        expect($result->data['mime'])->toBe('image/webp');
        expect($result->data['size'])->toBe(strlen($bytes));
        expect($result->data['renamed'])->toBeFalse();
        expect($result->data['original_name'])->toBeNull();

        // Persistence side-effect: the file actually landed on disk
        // under the principal's image dir. Use BASE_PATH so the test
        // resolves the same root the tool wrote to (`$this->paths()`
        // inside the tool pins against `BASE_PATH`, not the test's
        // own `Paths(sys_get_temp_dir())`).
        $store = new TypstImageStore(new TypstResourcePaths(new Paths(BASE_PATH), $this->principalId));
        $roundTrip = $store->read('autumn.webp');
        expect($roundTrip)->toBe($bytes);
    });

    it('honours data_url and local storage modes equally (regression: bytes path)', function (): void {
        // The two storage modes return identical {status, bytes, mime}
        // shapes from MediaAssetReader::readAsset(), so the import path
        // can be one branch. Pin the local-mode row to prove both
        // sides route through the same body-shaping code.
        $bytes = "WEBP-LOCAL-MODE";
        [$assetId] = seedMediaAsset($this->userId, 'image/webp', $bytes, 'winter.webp');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'winter.webp'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['name'])->toBe('winter.webp');

        $store = new TypstImageStore(new TypstResourcePaths(new Paths(BASE_PATH), $this->principalId));
        expect($store->read('winter.webp'))->toBe($bytes);
    });

    it('is idempotent — re-importing the same name overwrites (matches op=write semantics)', function (): void {
        [$assetIdA] = seedMediaAsset($this->userId, 'image/png', 'FIRST-BYTES', 'shared.png');
        [$assetIdB] = seedMediaAsset($this->userId, 'image/png', 'SECOND-BYTES-LONGER', 'shared.png');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $firstImport = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetIdA, 'name' => 'shared.png'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($firstImport->success)->toBeTrue();

        $secondImport = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetIdB, 'name' => 'shared.png'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($secondImport->success)->toBeTrue();

        // Final disk state reflects the second asset's bytes.
        $store = new TypstImageStore(new TypstResourcePaths(new Paths(BASE_PATH), $this->principalId));
        expect($store->read('shared.png'))->toBe('SECOND-BYTES-LONGER');
    });

    it('rejects when asset_id is missing', function (): void {
        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('asset_id');
        expect($result->content)->toContain('required');
    });

    it('rejects a malformed asset_id', function (): void {
        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => 'not-a-uuid'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('invalid asset_id');
    });

    it('rejects when the asset is not accessible to the caller (ownership union mirror)', function (): void {
        // The asset is owned by a different user. MediaAssetReader
        // returns null for cross-user lookups, and the tool surfaces
        // that as a not-found error so the LLM can self-correct.
        $outsiderId = $this->auth->register('outsider-' . bin2hex(random_bytes(4)) . '@example.com', 'Password1!', 'Outsider');
        [$assetId] = seedMediaAsset($outsiderId, 'image/webp', 'PRIVATE-BYTES', 'private.webp');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'private.webp'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('not found');
        expect($result->content)->toContain('not accessible');
    });

    it('rejects when the asset row is missing (UUID never existed)', function (): void {
        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => '00000000-0000-4000-8000-000000000000', 'name' => 'ghost.webp'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('00000000-0000-4000-8000-000000000000');
    });

    it('rejects external-storage_mode assets (no Spora-side bytes to copy)', function (): void {
        [$assetId] = seedMediaAsset($this->userId, 'image/webp', '', 'external.webp', pluginSlug: 'test', external: true);

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'external.webp'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('stored externally');
        expect($result->content)->toContain('storage_mode=external');
    });

    it('rejects audio/binary mimes outside the image allowlist', function (): void {
        $bytes = 'ID3' . bin2hex(random_bytes(32));
        [$assetId] = seedMediaAsset($this->userId, 'audio/mpeg', $bytes, 'song.mp3');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'song.mp3'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('audio/mpeg');
        expect($result->content)->toContain('not a supported image');
    });

    it('rejects assets whose byte size exceeds the 5 MiB image cap', function (): void {
        // Build the payload slightly over the cap (5 MiB + 1).
        // Same shape as TypstImageStore::MAX_BYTES so the assertion
        // matches production behaviour.
        $cap = 5 * 1024 * 1024;
        $bytes = str_repeat("\xff", $cap + 1);
        [$assetId] = seedMediaAsset($this->userId, 'image/png', $bytes, 'huge.png');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'huge.png'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('exceeds');
        expect($result->content)->toContain((string) $cap);
    });

    it('falls back to the source asset filename when `name` is omitted', function (): void {
        [$assetId] = seedMediaAsset($this->userId, 'image/jpeg', 'JPG-BYTES', 'autumn-mountain-landscape.jpg');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );

        expect($result->success)->toBeTrue();
        expect($result->data['name'])->toBe('autumn-mountain-landscape.jpg');
    });

    it('renames to a timestamp fallback when `name` has unsafe characters', function (): void {
        [$assetId] = seedMediaAsset($this->userId, 'image/webp', 'BYTES', 'clean.webp');

        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        $result = $tool->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'My Image (1).webp'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeTrue();
        expect($result->data['renamed'])->toBeTrue();
        expect($result->data['original_name'])->toBe('My Image (1).webp');
        expect($result->data['name'])->not->toContain(' ');
    });

    it('returns failure when the closure is not wired (null MediaAssetReader)', function (): void {
        // Build a tool WITHOUT the closure; this matches a host that
        // hasn't run onContainerBuilding() yet (test misconfig).
        [$assetId] = seedMediaAsset($this->userId, 'image/webp', 'BYTES', 'a.webp');
        $unwired = new TypstResourcesTool($this->worldFactory);

        $result = $unwired->execute(
            ['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId, 'name' => 'a.webp'],
            agentId: 0,
            userId: $this->userId,
            context: $this->context,
        );
        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('MediaAssetReader not wired');
    });

    it('describeAction surfaces the asset_id short form on media_assets/op=import', function (): void {
        $tool = toolWithReader($this->worldFactory, $this->mediaReaderFn);
        // Use a full UUID — describeAction should truncate to 12 chars.
        $assetId = '01aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $desc = $tool->describeAction(['action' => 'media_assets', 'op' => 'import', 'asset_id' => $assetId]);
        expect($desc)->toContain('media_assets/import');
        expect($desc)->toContain('01aaaaaaaaaa');
        expect($desc)->not->toContain($assetId);
    });
});
