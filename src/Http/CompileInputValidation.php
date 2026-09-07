<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Internal control-flow exception thrown by validation helpers in
 * {@see TypstCompileInputValidator} and
 * {@see TypstCompileController::requireUserId()} to unwind the
 * request-parsing stack without piling up `return $errorResponse`
 * statements (which Sonar's S1142 counts). Carries the
 * {@see JsonResponse} the controller would otherwise have returned
 * inline; the top-level {@see TypstCompileController::compile()}
 * catches it and unwraps.
 *
 * Not thrown across request boundaries; never escapes the controller.
 */
final class CompileInputValidation extends RuntimeException
{
    public function __construct(public readonly JsonResponse $response)
    {
        parent::__construct('compile input validation failed');
    }
}
