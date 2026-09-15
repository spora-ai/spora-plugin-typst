<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstWorldFactory;
use Spora\Plugins\Typst\Tools\TypstResourcesTool;

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

    $this->context = new Spora\Services\PrincipalContext(
        principalId: $this->principalId,
        type: 'user',
        ownerUserId: $this->userId,
        runnerUserId: $this->userId,
    );
});

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
        expect($result->content)->toContain('fonts, templates, examples, images');
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
        expect($result->content)->toContain('list, write, delete, read');
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
