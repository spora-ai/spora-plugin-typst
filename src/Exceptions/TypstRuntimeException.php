<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Plugin-specific runtime failure: missing principal scope,
 * unauthenticated caller, unreadable resource, invalid configuration,
 * or any other condition the plugin detected that prevents fulfilling
 * the request.
 *
 * Splits cleanly from {@see TypstCompilationException} (which carries
 * the structured {@see \Typst\Diagnostic\Diagnostic} array from ext-typst
 * and is mapped to HTTP 422 by the controller / producer pipeline) and
 * from SPL {@see RuntimeException} (which would lump our failures with
 * vendor failures for anyone catching at the SPL root).
 */
final class TypstRuntimeException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
