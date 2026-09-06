<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use Spora\Plugins\Typst\Exceptions\TypstInvalidArgumentException;

/**
 * Validates and normalises the basename of a `.typ` source row.
 *
 * Shared between the three surfaces that accept a user-chosen
 * filename for a playground / inline source row:
 *   - {@see \Spora\Plugins\Typst\Tools\TypstCompileTool} — tool-side
 *     `filename` parameter on `typst_compile` render.
 *   - {@see \Spora\Plugins\Typst\Http\TypstCompileController} —
 *     `POST /api/v1/typst/compile` body `name`.
 *   - {@see \Spora\Plugins\Typst\Http\TypstPlaygroundSourceController}
 *     — `POST /api/v1/typst/sources` body `filename`.
 *
 * Centralising the rule keeps the three endpoints in lockstep on
 * what counts as a valid basename: no path separators, no control
 * bytes, ≤ 128 chars, and `.typ` auto-appended when missing.
 *
 * The static helper throws {@see TypstInvalidArgumentException} so
 * the tool path can surface the failure as a failed `ToolResult`
 * and the controller paths can translate it into a 422 envelope
 * without each call site repeating the regex + length check.
 */
final class TypstFilename
{
    /**
     * Maximum permitted length of the trimmed basename, excluding
     * the `.typ` suffix the helper appends when missing. Mirrors
     * the previous `validateName()` / `validateFilename()` upper
     * bounds so the rule does not silently change for callers.
     */
    private const MAX_LENGTH = 128;

    /**
     * Characters disallowed anywhere in the basename — NUL bytes,
     * ASCII control chars (incl. \r, \n, \t), DEL, and the four
     * path-separator bytes (`/`, `\`). `php_uname` on Windows would
     * also bar `:` and `*`/`?`, but Spora's storage layer is
     * filesystem-agnostic and uses opaque tokens, so the POSIX-style
     * rule is sufficient here.
     */
    private const DISALLOWED = '/[\x00-\x1f\x7f\/\\\\]/';

    /**
     * Sanitise a user-supplied filename. Trims surrounding whitespace,
     * rejects empty / non-string / over-long / control-byte-laden
     * inputs, and appends `.typ` when the basename lacks it.
     *
     * @param mixed $raw Either null (treated as "use the default"),
     *                   an empty string (same), or a string. Anything
     *                   else is rejected with a clear message.
     * @param string $default Fallback basename used when `$raw` is null
     *                        or an empty / whitespace-only string.
     *
     * @throws TypstInvalidArgumentException When the input is not a
     *         string, contains path separators / control bytes, or
     *         exceeds {@see MAX_LENGTH} characters.
     */
    public static function sanitise(mixed $raw, string $default): string
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_string($raw)) {
            throw new TypstInvalidArgumentException('filename must be a string');
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return $default;
        }
        if (preg_match(self::DISALLOWED, $trimmed) === 1) {
            throw new TypstInvalidArgumentException(
                'filename contains illegal characters (no path separators or control bytes)',
            );
        }
        if (strlen($trimmed) > self::MAX_LENGTH) {
            throw new TypstInvalidArgumentException(
                sprintf('filename is too long (max %d chars)', self::MAX_LENGTH),
            );
        }
        if (!str_ends_with($trimmed, '.typ')) {
            $trimmed .= '.typ';
        }
        return $trimmed;
    }
}
