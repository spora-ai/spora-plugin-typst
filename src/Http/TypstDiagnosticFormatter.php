<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Spora\Plugins\Typst\Exceptions\TypstCompilationException;

/**
 * Build the API-facing diagnostic envelope for a {@see TypstCompilationException}.
 *
 * Sits beside {@see TypstCompileController} so the controller's
 * public-surface method count stays under Sonar's S1448 budget
 * (the controller owns the auth + body-parsing + producer pipeline;
 * this class owns the JSON shape that surfaces compiler errors).
 */
final class TypstDiagnosticFormatter
{
    /**
     * Build a list of `{"message": "..."}` entries for the API
     * envelope. Each Typst compiler diagnostic message is sanitised
     * to strip absolute paths / search-at-fragments before being
     * returned — those would leak the operator's filesystem layout
     * through the playground error panel.
     *
     * @return list<array{message: string}>
     */
    public static function diagnostics(TypstCompilationException $e): array
    {
        $out = [];
        foreach ($e->diagnostics as $diag) {
            $out[] = ['message' => self::sanitise($diag->message())];
        }
        if ($out === []) {
            $out[] = ['message' => self::sanitise($e->getMessage())];
        }
        return $out;
    }

    /**
     * Strip absolute filesystem paths and "searched at <path>"
     * fragments from compiler diagnostic strings. Typst includes
     * both on errors and the operator-facing playground UI must not
     * echo them back — they leak `/Users/...`-style paths.
     *
     *   1. replace any `(searched at <path>)` clause with
     *      `(file not found)` — the diagnostic still tells the
     *      user WHAT happened without telling them WHERE we looked.
     *   2. strip any remaining absolute filesystem path
     *      (`/Users/...`, `/home/...`, `C:\...`) and replace with
     *      `<path>` — a final safety net for any future diagnostic
     *      shape that we don't catch in (1).
     */
    public static function sanitise(string $message): string
    {
        $message = preg_replace(
            '/\(searched at [^)]+\)/',
            '(file not found)',
            $message,
        ) ?? $message;
        // POSIX absolute paths (incl. /Users/..., /home/..., /opt/...,
        // /var/..., /tmp/..., /private/... on macOS).
        $message = preg_replace(
            '#(/[A-Za-z0-9._-]+){2,}#',
            '<path>',
            $message,
        ) ?? $message;
        // Windows-style absolute paths.
        $message = preg_replace(
            '#([A-Za-z]:\\[A-Za-z0-9._-]+){2,}#',
            '<path>',
            $message,
        ) ?? $message;
        return $message;
    }
}
