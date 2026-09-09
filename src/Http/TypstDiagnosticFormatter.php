<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Spora\Plugins\Typst\Exceptions\TypstCompilationException;
use Typst\Diagnostic\Severity;

/**
 * Build the API-facing diagnostic envelope for a {@see TypstCompilationException}.
 *
 * Sits beside {@see TypstCompileController} so the controller's
 * public-surface method count stays under Sonar's S1448 budget
 * (the controller owns the auth + body-parsing + producer pipeline;
 * this class owns the JSON shape that surfaces compiler errors).
 *
 * Wire shape (one entry per diagnostic):
 *
 *   {
 *     "message":  "<sanitised message>",
 *     "severity": "error" | "warning",
 *     "hint":     "<optional fix suggestion>"
 *   }
 *
 * `severity` is the ext-typst Severity enum lower-cased — the
 * frontend uses it to colour the entry red (error) vs amber
 * (warning). `hint` is either:
 *   - the compiler's own built-in hint (via `Diagnostic::hints()`),
 *     or
 *   - a synthetic hint {@see hintFor()} produces for the most common
 *     operator mistakes (file not found, missing font, unknown
 *     variable). Hints are best-effort — an unmatched error gets no
 *     hint entry and the operator sees the raw message alone.
 *
 * Backward compatibility: the previous envelope only had `message`.
 * Clients that only read `message` are unaffected; the new
 * `severity` / `hint` fields are additive.
 */
final class TypstDiagnosticFormatter
{
    /**
     * Build a list of `{"message", "severity", "hint"?}` entries for
     * the API envelope. Each Typst compiler diagnostic message is
     * sanitised to strip absolute paths / search-at-fragments before
     * being returned — those would leak the operator's filesystem
     * layout through the playground error panel.
     *
     * @return list<array{message: string, severity: string, hint?: string}>
     */
    public static function diagnostics(TypstCompilationException $e): array
    {
        $out = [];
        foreach ($e->diagnostics as $diag) {
            $sanitised = self::sanitise($diag->message());
            $entry = [
                'message'  => $sanitised,
                'severity' => self::severityLabel($diag->severity()),
            ];
            $hint = self::resolveHint($diag, $sanitised);
            if ($hint !== null) {
                $entry['hint'] = $hint;
            }
            $out[] = $entry;
        }
        if ($out === []) {
            $sanitised = self::sanitise($e->getMessage());
            $entry = [
                'message'  => $sanitised,
                'severity' => 'error',
            ];
            $hint = self::hintFor($sanitised);
            if ($hint !== null) {
                $entry['hint'] = $hint;
            }
            $out[] = $entry;
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
        // Windows-style absolute paths. Match `X:\` plus at least one
        // path component, plus one more to confirm it's a real absolute
        // path (not `C:\foo` as a bare drive-letter shortcut).
        $message = preg_replace(
            '#([A-Za-z]:(\\\\[A-Za-z0-9._-]+){2,})#',
            '<path>',
            $message,
        ) ?? $message;
        return $message;
    }

    /**
     * Map a {@see Severity} enum to the lowercase wire label the
     * frontend's colour rules key off.
     *
     * The `default` arm catches Severity::Hint (an existing case in
     * the ext-typst enum, not a future one) — a future ext-typst
     * version that routes hints through the same diagnostic
     * channel would currently see them labelled as `error` on the
     * wire. This is unreachable in production today because the
     * producer's summariseDiagnostics() filters to Severity::Error
     * before constructing the exception (see
     * {@see TypstRenderProducer::summariseDiagnostics}); the
     * default arm is a defensive net for that hypothetical shape.
     */
    private static function severityLabel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error   => 'error',
            Severity::Warning => 'warning',
            default           => 'error',
        };
    }

    /**
     * Compose the hint for a diagnostic: prefer the compiler's own
     * {@see \Typst\Diagnostic\Diagnostic::hints()} (Typst ships
     * pre-baked suggestions for the common cases), then fall back to
     * {@see hintFor()} for the operator-specific mistakes only this
     * plugin can recognise (file-not-found convention, missing font,
     * etc.).
     *
     * `object` rather than `Diagnostic` because PECL's Diagnostic
     * class is `final` and can't be mocked — tests pass duck-typed
     * objects (anonymous classes with severity/message/hints). The
     * production path uses real Diagnostic instances from
     * {@see \Typst\Inspector::inspectString()}.
     */
    private static function resolveHint(object $diag, string $sanitised): ?string
    {
        // Compiler hints come first when present — Typst's own
        // suggestions are usually more accurate than ours.
        $compilerHints = method_exists($diag, 'hints') ? $diag->hints() : [];
        if ($compilerHints !== []) {
            return implode(' ', $compilerHints);
        }
        return self::hintFor($sanitised);
    }

    /**
     * Pattern-matched hints for the operator mistakes only this
     * plugin can recognise. Generic Typst errors fall through to the
     * compiler's own {@see \Typst\Diagnostic\Diagnostic::hints()}.
     *
     * The first matching pattern wins; patterns are intentionally
     * conservative so we don't mis-diagnose unrelated errors. Add
     * new patterns at the bottom of the chain, not the top, so a
     * narrower pattern can override a wider one later.
     */
    private static function hintFor(string $message): ?string
    {
        // File-not-found after sanitisation: the `(file not found)`
        // placeholder is what `sanitise()` produces from
        // `(searched at <path>)`. Most often this hits `#include` /
        // `#import` of a basename the operator uploaded via the
        // Templates or Examples tab — the plugin's `template_dir`
        // scopes imports to the principal root, so the basename
        // alone won't resolve. Direct the operator to the right
        // prefix.
        if (str_contains($message, '(file not found)')) {
            $hint = 'Imports in the Editor compile from the principal root; uploaded templates live under "templates/" and examples under "examples/". Use #import "templates/foo.typ" or #include "examples/bar.typ" — upload via the Templates or Examples tab.';
        } elseif (str_contains($message, 'no font could be found')) {
            // The operator referenced a font by name that the bundled
            // + principal-tier `font_dirs` don't contain.
            $hint = 'Upload the font via the Fonts tab, or reference one of the bundled fonts (Inter, DejaVu Sans, Latin Modern Math) by its basename.';
        } elseif (preg_match('/\bunknown variable\b/', $message) === 1) {
            // Most often a typo in a function name or a missing
            // import.
            $hint = 'Check the spelling, or add the missing #import at the top of the source.';
        } else {
            $hint = null;
        }
        return $hint;
    }
}
