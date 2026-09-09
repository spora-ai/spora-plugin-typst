<?php

declare(strict_types=1);

use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Spora\Plugins\Typst\Http\TypstDiagnosticFormatter;
use Typst\Diagnostic\Severity;

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

/**
 * Build a duck-typed diagnostic stand-in. The real
 * {@see Typst\Diagnostic\Diagnostic} class is a `final` PECL
 * extension class that can't be subclassed or mocked, so the
 * formatter is written against an `object` shape with the three
 * methods it needs (severity/message/hints). Tests instantiate
 * anonymous classes with just those methods; the production path
 * uses real Diagnostic instances from
 * {@see Typst\Inspector::inspectString()}.
 */
function buildDiagnostic(Severity $severity, string $message, array $hints = []): object
{
    return new class ($severity, $message, $hints) {
        public function __construct(
            private readonly Severity $severityValue,
            private readonly string $messageValue,
            private readonly array $hintsValue,
        ) {}
        public function severity(): Severity
        {
            return $this->severityValue;
        }
        public function message(): string
        {
            return $this->messageValue;
        }
        public function hints(): array
        {
            return $this->hintsValue;
        }
    };
}

describe('TypstDiagnosticFormatter::diagnostics', function (): void {
    beforeEach(function (): void {
        // The `Severity` enum and the diagnostic shape come from
        // ext-typst at runtime. The Pest bootstrap loads the stubs
        // when ext-typst isn't installed (CI vanilla ubuntu), so the
        // file's `use Typst\Diagnostic\Severity` resolves — but the
        // tests below exercise the formatter against real
        // Severity::Warning / Severity::Error values that the
        // production code passes through to the diagnostic formatter.
        // Skip when the real extension isn't loaded so the suite
        // still passes CI without ext-typst.
        if (!extension_loaded('typst')) {
            $this->markTestSkipped('ext-typst is not loaded');
        }
    });

    it('falls back to the exception message with severity=error when diagnostics is empty', function (): void {
        $e = new TypstCompilationException('top-level compile failure', []);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out)->toBe([
            ['message' => 'top-level compile failure', 'severity' => 'error'],
        ]);
    });

    it('sanitises the exception message in the empty-diagnostics fallback', function (): void {
        $e = new TypstCompilationException(
            'cannot open /Users/alice/main.typ (searched at /Users/alice)',
            [],
        );
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out[0]['message'])->toContain('<path>')
            ->and($out[0]['message'])->not->toContain('/Users/alice')
            ->and($out[0]['severity'])->toBe('error');
    });

    it('propagates severity=warning from the ext-typst diagnostic', function (): void {
        $diag = buildDiagnostic(Severity::Warning, 'this variable is unused');
        $e = new TypstCompilationException('compile warnings', [$diag]);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out)->toBe([
            ['message' => 'this variable is unused', 'severity' => 'warning'],
        ]);
    });

    it('attaches a file-not-found hint when the sanitised message contains (file not found)', function (): void {
        $diag = buildDiagnostic(
            Severity::Error,
            'package not found (searched at /Users/alice/typst/packages/preview)',
        );
        $e = new TypstCompilationException('compile failure', [$diag]);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out[0]['message'])->toContain('(file not found)')
            ->and($out[0]['severity'])->toBe('error')
            ->and($out[0]['hint'] ?? '')->toContain('templates/')
            ->and($out[0]['hint'] ?? '')->toContain('examples/');
    });

    it('attaches a missing-font hint when the message references fonts', function (): void {
        $diag = buildDiagnostic(Severity::Error, 'no font could be found');
        $e = new TypstCompilationException('compile failure', [$diag]);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out[0]['hint'] ?? '')->toContain('Fonts tab');
    });

    it('attaches an unknown-variable hint for typo-class errors', function (): void {
        $diag = buildDiagnostic(Severity::Error, 'unknown variable: evnt-teaser');
        $e = new TypstCompilationException('compile failure', [$diag]);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out[0]['hint'] ?? '')->toContain('#import');
    });

    it('prefers the compiler-provided hints over our pattern-matched ones', function (): void {
        $diag = buildDiagnostic(
            Severity::Error,
            'package not found (searched at /Users/alice/typst/packages/preview)',
            ['Try installing the package via `typst pm install`.'],
        );
        $e = new TypstCompilationException('compile failure', [$diag]);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out[0]['hint'])->toBe('Try installing the package via `typst pm install`.');
    });

    it('omits the hint key when neither the compiler nor our matcher has one', function (): void {
        $diag = buildDiagnostic(Severity::Error, 'expected expression');
        $e = new TypstCompilationException('compile failure', [$diag]);
        $out = TypstDiagnosticFormatter::diagnostics($e);
        expect($out)->toBe([
            ['message' => 'expected expression', 'severity' => 'error'],
        ]);
        expect(array_key_exists('hint', $out[0]))->toBeFalse();
    });
});
