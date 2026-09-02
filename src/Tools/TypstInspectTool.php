<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Tools;

use InvalidArgumentException;
use RuntimeException;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Read-only diagnostic pass over Typst source.
 *
 * Mirrors {@see TypstRenderTool}'s source
 * resolution so the LLM can inspect a `.typ` asset or an inline
 * string without committing to a render. Uses ext-typst's
 * {@see \Typst\Inspector} directly — no `Compiler::compileString()`
 * side effects, no document bytes produced, no media-derivative row.
 *
 * Returns a structured diagnostic list so the agent can iterate on
 * the source without paying for full PDF/PNG/SVG cycles on every
 * attempt. Useful as a "fast feedback" tool that runs before
 * `typst_render` when the source might be subtly broken.
 */
#[Tool(
    name: 'typst_inspect',
    description: 'Compile-check Typst source without producing a derivative. Returns the structured error and warning list from ext-typst\'s Inspector. Provide source as an inline string OR a media asset id (the .typ file).',
)]
#[ToolParameter(
    name: 'source',
    type: 'string',
    description: 'Inline Typst source to inspect. Provide one of `source` or `file`.',
    required: false,
)]
#[ToolParameter(
    name: 'file',
    type: 'string',
    description: 'Media asset id of a previously-uploaded .typ file to inspect. Provide one of `source` or `file`.',
    required: false,
)]
final class TypstInspectTool extends AbstractTypstTool
{
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        try {
            $resolved = $this->resolveSource($arguments, $agentId, $userId, $context);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return new ToolResult(false, $e->getMessage());
        }

        try {
            $stack = $this->worldFactory->build($context?->principalId);
            $result = $stack['inspector']->inspectString($resolved['bytes']);
        } catch (Throwable $e) {
            return new ToolResult(false, 'typst_inspect: ' . $e->getMessage());
        }

        $errors   = $result->errors();
        $warnings = $result->warnings();

        $lines = [];
        if ($errors === [] && $warnings === []) {
            $lines[] = 'typst_inspect: no diagnostics (the source parses cleanly under the inspector)';
        } else {
            $lines[] = sprintf(
                'typst_inspect: %d error(s), %d warning(s)',
                count($errors),
                count($warnings),
            );
            foreach ($errors as $d) {
                $lines[] = $this->renderDiagnostic('error', $d);
            }
            foreach ($warnings as $d) {
                $lines[] = $this->renderDiagnostic('warning', $d);
            }
        }

        return ToolResult::ok(
            content: implode("\n", $lines),
            data: [
                'errors'   => array_map(static fn($d): array => [
                    'severity' => $d->severity()->name,
                    'message'  => $d->message(),
                    'hints'    => array_map(static fn($h): string => (string) $h, $d->hints()),
                ], $errors),
                'warnings' => array_map(static fn($d): array => [
                    'severity' => $d->severity()->name,
                    'message'  => $d->message(),
                    'hints'    => array_map(static fn($h): string => (string) $h, $d->hints()),
                ], $warnings),
                'success'  => $result->success(),
            ],
        );
    }

    public function describeAction(array $arguments): string
    {
        $what = isset($arguments['file']) ? 'file=' . substr((string) $arguments['file'], 0, 8) : 'inline source';
        return 'Typst inspect (' . $what . ')';
    }

    private function renderDiagnostic(string $label, \Typst\Diagnostic\Diagnostic $d): string
    {
        $hints = $d->hints();
        $hintSuffix = $hints !== [] ? "\n    hint: " . implode(' / ', array_map('strval', $hints)) : '';
        return sprintf('- %s: %s%s', $label, $d->message(), $hintSuffix);
    }
}
