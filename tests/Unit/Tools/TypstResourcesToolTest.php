<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Plugins\Typst\Services\TypstImageStore;
use Spora\Plugins\Typst\Services\TypstResourcePaths;
use Spora\Plugins\Typst\Services\TypstResourceStore;
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

    $resourcePaths = new TypstResourcePaths($paths, principalId: $this->principalId);
    $this->resourceStore = new TypstResourceStore($resourcePaths);
    $this->imageStore = new TypstImageStore($resourcePaths);

    $this->tool = new TypstResourcesTool(
        $this->worldFactory,
        $this->resourceStore,
        $this->imageStore,
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
        expect($result->content)->toContain('list, write, delete');
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
