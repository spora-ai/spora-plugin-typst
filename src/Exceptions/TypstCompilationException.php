<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when ext-typst refuses a compile, the source can't be read,
 * a referenced `#include` is missing, or any other failure surfaces
 * inside the {@see \Spora\Plugins\Typst\Producers\TypstRenderProducer}.
 *
 * The producer's `produce()` method rethrows everything as this type
 * so the {@see \Spora\Http\MediaDerivativeController} maps it to a
 * 422 the same way it does for other producer failures.
 *
 * The `$diagnostics` array carries the
 * {@see \Typst\Diagnostic\Diagnostic} entries from the inspector so
 * the tool layer can emit Typst's structured errors verbatim in its
 * `ToolResult::error` content — agent-facing render errors are
 * dramatically more useful when the LLM sees the original compiler
 * diagnostics than when it sees a flattened message.
 */
final class TypstCompilationException extends RuntimeException
{
    /**
     * @param list<\Typst\Diagnostic\Diagnostic> $diagnostics
     */
    public function __construct(
        string $message,
        public readonly array $diagnostics = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
