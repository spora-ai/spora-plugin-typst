<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Typst\Exceptions\TypstInvalidArgumentException;
use Spora\Plugins\Typst\Services\TypstFilename;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decode + validate the POST body of {@see TypstCompileController::compile()}.
 *
 * Extracted from the controller so the controller's public-surface
 * method count stays under Sonar's S1448 budget (≤20 methods) — the
 * controller owns the auth + producer pipeline, this class owns the
 * `parse → throw → return` shape that turns a Symfony request into a
 * {@see CompileInputs} value object.
 *
 * Uses {@see JsonControllerHelpers} trait directly so it doesn't need
 * to depend on a controller instance — the same `safeDecodeJson` /
 * `unprocessable` / `unauthenticated` envelope shape the controller
 * uses is available here.
 *
 * Returns {@see CompileInputs} on success; throws
 * {@see CompileInputValidation} (caught by the controller's
 * `compile()` method) on validation failure so each helper stays
 * within Sonar's S1142 `return`-statement budget.
 */
final class TypstCompileInputValidator
{
    use JsonControllerHelpers;

    /** Default playground filename when the request omits `name`. */
    private const DEFAULT_NAME = 'playground.typ';

    /**
     * Decode the request body, extract every compile-relevant field,
     * and return a {@see CompileInputs} value object. Throws
     * {@see CompileInputValidation} (with the right HTTP envelope
     * already attached) on any rejection.
     *
     * Body shape:
     *
     *   {
     *     "source": "= Hello, Typst!\n",                // required, non-empty
     *     "name":   "letter.typ",                       // optional, defaults to DEFAULT_NAME
     *     "format": "pdf" | "png" | "svg",              // optional, defaults to pdf
     *     "page":   0,                                  // optional, png only, clamped to ≥ 0
     *     "dpi":    144                                 // optional, png only, clamped 36..600
     *   }
     */
    public function parseCompileInputs(Request $request): CompileInputs
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            throw new CompileInputValidation($body);
        }

        $source = $this->extractSource($body);
        $name = $this->validateName($body['name'] ?? null);
        $format = $this->extractFormat($body);
        $page = isset($body['page']) ? max(0, (int) $body['page']) : null;
        $dpi = isset($body['dpi']) ? max(36.0, min(600.0, (float) $body['dpi'])) : null;

        return new CompileInputs($source, $name, $format, $page, $dpi);
    }

    /**
     * Pull `source` out of the body. Required, must be a non-empty
     * string after trimming — whitespace-only is treated as missing.
     */
    private function extractSource(array $body): string
    {
        $source = $body['source'] ?? null;
        if (!is_string($source) || trim($source) === '') {
            throw new CompileInputValidation(
                $this->unprocessable('VALIDATION_ERROR', 'source is required and must be a non-empty string'),
            );
        }
        return $source;
    }

    /**
     * Pull `format` out of the body, normalising to lowercase so
     * `"PDF"` / `"Png"` / `"SVG"` all work. Defaults to `pdf`.
     */
    private function extractFormat(array $body): string
    {
        $format = strtolower(trim((string) ($body['format'] ?? 'pdf')));
        if (!in_array($format, ['pdf', 'png', 'svg'], true)) {
            throw new CompileInputValidation(
                $this->unprocessable('VALIDATION_ERROR', sprintf(
                    'invalid format "%s" (expected: pdf, png, svg)',
                    $format,
                )),
            );
        }
        return $format;
    }

    /**
     * Resolve the playground filename. Accepts null/empty (→ default),
     * a plain basename, or a basename with the `.typ` suffix added if
     * missing. Rejects anything that would escape the principal's
     * directory (path traversal, control chars, NUL bytes).
     *
     * The actual regex / length rule lives in
     * {@see TypstFilename::sanitise()} so this validator, the
     * playground source controller, and the `typst_compile` tool
     * share one definition.
     */
    private function validateName(mixed $raw): string
    {
        try {
            return TypstFilename::sanitise($raw, self::DEFAULT_NAME);
        } catch (TypstInvalidArgumentException $e) {
            throw new CompileInputValidation(
                $this->unprocessable('VALIDATION_ERROR', $e->getMessage()),
            );
        }
    }
}
