<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

/**
 * Validated inputs for {@see TypstCompileController::compile()}.
 *
 * Carries the sanitised scalars from the POST body so the
 * orchestrator can pass them around without re-validating or
 * re-decoding. Owned only by {@see TypstCompileInputValidator}
 * and {@see TypstCompileController::runCompile()}; callers receive
 * a JsonResponse, never this object.
 */
final readonly class CompileInputs
{
    public function __construct(
        public string $source,
        public string $name,
        public string $format,
        public ?int $page,
        public ?float $dpi,
    ) {}
}
