<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use Composer\InstalledVersions;
use OutOfBoundsException;
use RuntimeException;
use Spora\Core\Paths;

/**
 * Computes the on-disk locations of Typst plugin resources.
 *
 * Three kinds, two tiers:
 *
 *   | Kind      | Tier 1 (skill, read-only)        | Tier 2 (principal, writable)             |
 *   |-----------|----------------------------------|------------------------------------------|
 *   | `font`    | `<plugin>/skills/typst/fonts/`   | `<storage>/typst/fonts/<principal>/`    |
 *   | `template`| `<plugin>/skills/typst/templates/`|`<storage>/typst/templates/<principal>/`  |
 *   | `example` | `<plugin>/skills/typst/examples/`| `<storage>/typst/examples/<principal>/`   |
 *   | `image`   | (none — examples are uploads)    | `<storage>/typst/images/<principal>/`     |
 *
 * `template` and `example` look the same to Typst — both are
 * `.typ` files referenced via `#include`. The distinction is purely
 * navigational: `template` is for end-user document skeletons
 * (invoice.typ, letter.typ), `example` is for pattern snippets the
 * LLM reads to learn a primitive (headings.typ, table.typ).
 *
 * Tier 1 (skill-shipped) is the canonical, versioned set that ships
 * with the plugin. Tier 2 (per-principal) is operator-uploaded and
 * shadows tier 1 on basename collision, mirroring the cascade the
 * earlier font + example design locked in. The listing API merges
 * both tiers.
 *
 * The plugin's TypstWorldFactory uses these paths to set
 * `template_dir` (per-principal so `#include "templates/foo.typ"` and
 * `#include "examples/bar.typ"` both resolve under it) and
 * `font_dirs` (an array of tier-1 + tier-2 paths Typst searches
 * recursively). See {@see TypstWorldFactory} for the world config.
 */
final class TypstResourcePaths
{
    public const KIND_FONT = 'font';
    public const KIND_TEMPLATE = 'template';
    public const KIND_EXAMPLE = 'example';
    public const KIND_IMAGE = 'image';

    /** All kinds the API surface exposes for listing + upload. */
    public const KINDS = [
        self::KIND_FONT,
        self::KIND_TEMPLATE,
        self::KIND_EXAMPLE,
    ];

    private const PLUGIN_PACKAGE = 'spora-ai/spora-plugin-typst';

    public function __construct(
        private readonly Paths $paths,
        // `?int` — null means "no principal scope" (used by the world
        // factory's DI path, where PHP-DI autowires a singleton at
        // boot before any request has resolved a principal). Per-
        // request callers (HTTP controllers) always pass an int.
        private readonly ?int $principalId = null,
    ) {}

    /**
     * Directory holding the plugin-shipped (tier-1) resources.
     * Always inside the plugin's `skills/typst/` directory so they
     * travel with the plugin package — no symlinks, no bootstrap-time
     * copy.
     */
    public function skillDirectory(): string
    {
        return $this->pluginInstallRoot() . '/skills/typst';
    }

    public function skillFontDirectory(): string
    {
        return $this->skillDirectory() . '/fonts';
    }

    public function skillTemplateDirectory(): string
    {
        return $this->skillDirectory() . '/templates';
    }

    public function skillExampleDirectory(): string
    {
        return $this->skillDirectory() . '/examples';
    }

    public function principalDirectory(): string
    {
        // Per-principal "base" directory. Typst's `template_dir` is
        // set to this so its `templates/` and `examples/` subdirs are
        // both visible under `#include "templates/foo"` /
        // `#include "examples/bar"`.
        if ($this->principalId === null) {
            throw new RuntimeException('TypstResourcePaths::principalDirectory() called without a principal scope');
        }
        return $this->paths->storage('typst') . '/' . $this->principalId;
    }

    public function principalFontDirectory(): string
    {
        // Kind-first: `<storage>/typst/fonts/<principal>/` — matches
        // the skill-shipped tier-1 path layout (which is also
        // kind-first: `<plugin>/skills/typst/fonts/`).
        if ($this->principalId === null) {
            throw new RuntimeException('TypstResourcePaths::principalFontDirectory() called without a principal scope');
        }
        return $this->paths->storage('typst/fonts') . '/' . $this->principalId;
    }

    public function principalTemplateDirectory(): string
    {
        if ($this->principalId === null) {
            throw new RuntimeException('TypstResourcePaths::principalTemplateDirectory() called without a principal scope');
        }
        return $this->paths->storage('typst/templates') . '/' . $this->principalId;
    }

    public function principalExampleDirectory(): string
    {
        if ($this->principalId === null) {
            throw new RuntimeException('TypstResourcePaths::principalExampleDirectory() called without a principal scope');
        }
        return $this->paths->storage('typst/examples') . '/' . $this->principalId;
    }

    public function principalImageDirectory(): string
    {
        if ($this->principalId === null) {
            throw new RuntimeException('TypstResourcePaths::principalImageDirectory() called without a principal scope');
        }
        return $this->paths->storage('typst/images') . '/' . $this->principalId;
    }

    /**
     * True iff `$basename` exists in tier 1 for `$kind`. Used by the
     * admin UI to mark a resource as "built-in, can't delete".
     */
    public function isSkillShipped(string $kind, string $basename): bool
    {
        return is_file($this->tierOnePath($kind, $basename));
    }

    public function tierOnePath(string $kind, string $basename): string
    {
        return $this->directoryFor($kind, tierOne: true) . '/' . $basename;
    }

    public function tierTwoPath(string $kind, string $basename): string
    {
        return $this->directoryFor($kind, tierOne: false) . '/' . $basename;
    }

    /**
     * Lists basenames visible to this principal. Tier-2 shadows tier-1
     * on basename collision. Returns sorted by basename (case-insensitive)
     * so the admin UI is stable across requests.
     *
     * @return list<string>
     */
    public function listBasenames(string $kind): array
    {
        $tierOne = $this->listDirectory($this->directoryFor($kind, tierOne: true));
        $tierTwo = $this->listDirectory($this->directoryFor($kind, tierOne: false));

        // tier-2 wins; preserve insertion order within a tier.
        $out = $tierTwo;
        foreach ($tierOne as $name) {
            if (!in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        usort($out, static fn(string $a, string $b): int => strcasecmp($a, $b));
        return $out;
    }

    /**
     * Title-case label for the resource listing UI.
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_FONT     => 'Fonts',
            self::KIND_TEMPLATE => 'Templates',
            self::KIND_EXAMPLE  => 'Examples',
            default             => throw new RuntimeException(sprintf(
                'TypstResourcePaths: unknown kind "%s"',
                $kind,
            )),
        };
    }

    public static function assertValidKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new RuntimeException(sprintf(
                'TypstResourcePaths: invalid kind "%s" (must be one of: %s)',
                $kind,
                implode(', ', self::KINDS),
            ));
        }
    }

    /**
     * Resolve the plugin's install root from Composer's runtime
     * metadata. Falls back to `dirname(__DIR__, 2)` (i.e. the plugin
     * root when running from `src/Services/`) when the package isn't
     * registered with InstalledVersions — happens during early-boot
     * test setups that haven't yet required the autoloader.
     */
    private function pluginInstallRoot(): string
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                $root = InstalledVersions::getInstallPath(self::PLUGIN_PACKAGE);
                if (is_string($root) && $root !== '') {
                    return $root;
                }
            } catch (OutOfBoundsException) {
                // Package not registered — fall through to the
                // __DIR__-based fallback.
            }
        }
        return dirname(__DIR__, 2);
    }

    private function directoryFor(string $kind, bool $tierOne): string
    {
        self::assertValidKind($kind);
        // assertValidKind narrows $kind to one of KINDS; the `default`
        // arm is dead code that PHPStan needs to keep match()
        // exhaustive, but it never executes.
        if ($tierOne) {
            return match ($kind) {
                self::KIND_FONT     => $this->skillFontDirectory(),
                self::KIND_TEMPLATE => $this->skillTemplateDirectory(),
                self::KIND_EXAMPLE  => $this->skillExampleDirectory(),
                default             => throw new RuntimeException(sprintf(
                    'TypstResourcePaths: unhandled kind "%s" (assertValidKind should have rejected this)',
                    $kind,
                )),
            };
        }
        // Tier-2 paths need a principal scope; the world factory
        // (no principal) never calls into tier-2.
        return match ($kind) {
            self::KIND_FONT     => $this->principalFontDirectory(),
            self::KIND_TEMPLATE => $this->principalTemplateDirectory(),
            self::KIND_EXAMPLE  => $this->principalExampleDirectory(),
            default             => throw new RuntimeException(sprintf(
                'TypstResourcePaths: unhandled kind "%s" (assertValidKind should have rejected this)',
                $kind,
            )),
        };
    }

    /**
     * @return list<string>
     */
    private function listDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }
        $out = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') {
                continue;
            }
            if (is_file($dir . '/' . $name)) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
