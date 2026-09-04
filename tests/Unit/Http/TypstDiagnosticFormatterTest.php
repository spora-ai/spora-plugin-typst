<?php

declare(strict_types=1);

use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Http\TypstDiagnosticFormatter;

describe('TypstDiagnosticFormatter::sanitise', function (): void {
    it('replaces (searched at <path>) with (file not found)', function (): void {
        expect(TypstDiagnosticFormatter::sanitise(
            'package not found (searched at /Users/alice/typst/packages/preview)',
        ))->toBe('package not found (file not found)');
    });

    it('redacts POSIX absolute paths', function (): void {
        expect(TypstDiagnosticFormatter::sanitise(
            'cannot open /Users/alice/project/main.typ',
        ))->toContain('<path>')
            ->and(TypstDiagnosticFormatter::sanitise(
                'cannot open /Users/alice/project/main.typ',
            ))->not->toContain('/Users/alice');
    });

    it('redacts Windows-style absolute paths', function (): void {
        $msg = 'cannot open C:\\Users\\alice\\project\\main.typ';
        expect(TypstDiagnosticFormatter::sanitise($msg))
            ->toContain('<path>');
    });

    it('passes through messages with no path fragments', function (): void {
        expect(TypstDiagnosticFormatter::sanitise('unknown variable x'))
            ->toBe('unknown variable x');
    });

    it('handles empty strings', function (): void {
        expect(TypstDiagnosticFormatter::sanitise(''))->toBe('');
    });
});

describe('TypstDiagnosticFormatter::diagnostics', function (): void {
    it('falls back to the exception message when diagnostics is empty', function (): void {
        $e = new TypstCompilationException('top-level compile failure', []);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out)->toBe([['message' => 'top-level compile failure']]);
    });

    it('sanitises the exception message in the empty-diagnostics fallback', function (): void {
        $e = new TypstCompilationException(
            'cannot open /Users/alice/main.typ (searched at /Users/alice)',
            [],
        );
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out[0]['message'])->toContain('<path>')
            ->and($out[0]['message'])->not->toContain('/Users/alice');
    });
});
