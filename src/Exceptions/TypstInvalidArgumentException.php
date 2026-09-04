<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a Typst plugin caller's arguments fail structural
 * validation — missing required inputs, mutually exclusive options
 * both present, or values outside the accepted domain.
 *
 * Extends the SPL {@see InvalidArgumentException} so callers that
 * catch the SPL base continue to work; the dedicated subclass lets
 * controller / tool layers distinguish "you sent bad input" from
 * "the underlying engine failed" without sniffing message strings.
 */
final class TypstInvalidArgumentException extends InvalidArgumentException {}
