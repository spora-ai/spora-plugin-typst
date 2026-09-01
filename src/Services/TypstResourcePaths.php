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
 * Two tiers, mirroring the design locked in
 * `spora-workspace/plans/typst-plugin.md` § Storage layout:
 *
 *  - **Tier 1 (skill, read-only).** Fonts and example templates shipped
 *    with the plugin's `skills/typst/` directory. These are versioned
 *    with the plugin and shared across every install: an operator can
 *    rely on Inter OFL being present without uploading anything. The
 *    path resolves through {@see InstalledVersions::getInstallPath()} so
 *    the plugin's own install root is the anchor, regardless of whether
 *    the operator installed from Packagist or a path repo.
 *
 *  - **Tier 2 (principal, writable).** Uploads from the admin panel or
 *    a future `POST /api/v1/typst/fonts` endpoint. Stored under
 *    `<storage>/typst/{fonts,examples}/<principal-id>/`. This is the
 *    principal-scoped scratch space the operator can grow without
 *    touching the plugin's read-only tier-1 install.
 *
 * Reads consult tier 1 first, tier 2 second; writes only ever touch
 * tier 2. Listing returns the union (deduplicated by basename — tier 2
 * shadows tier 1 on basename collision, mirroring a typical CSS
 * cascade).
 */
final class TypstResourcePaths
{
    public const KIND_FONT = 'font';
    public const KIND_EXAMPLE = 'example';

    private const PLUGIN_PACKAGE = 'spora-ai/spora-plugin-typst';

    public function __construct(
        private readonly Paths $paths,
        private readonly int $principalId,
    ) {}

    /**
     * Directory holding the plugin-shipped (tier-1) font + example files.
     * Always inside the plugin's `skills/typst/` directory so it travels
     * with the plugin package — no symlinks, no bootstrap-time copy.
     */
    public function skillDirectory(): string
    {
        return $this->pluginInstallRoot() . '/skills/typst';
    }

    public function skillFontDirectory(): string
    {
        return $this->skillDirectory() . '/fonts';
    }

    public function skillExampleDirectory(): string
    {
        return $this->skillDirectory() . '/examples';
    }

    public function principalFontDirectory(): string
    {
        return $this->paths->storage('typst/fonts') . '/' . $this->principalId;
    }

    public function principalExampleDirectory(): string
    {
        return $this->paths->storage('typst/examples') . '/' . $this->principalId;
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
            self::KIND_FONT    => 'Fonts',
            self::KIND_EXAMPLE => 'Examples',
            default            => throw new RuntimeException(sprintf(
                'TypstResourcePaths: unknown kind "%s"',
                $kind,
            )),
        };
    }

    public static function assertValidKind(string $kind): void
    {
        if ($kind !== self::KIND_FONT && $kind !== self::KIND_EXAMPLE) {
            throw new RuntimeException(sprintf(
                'TypstResourcePaths: invalid kind "%s" (must be "%s" or "%s")',
                $kind,
                self::KIND_FONT,
                self::KIND_EXAMPLE,
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
        if ($tierOne) {
            return $kind === self::KIND_FONT
                ? $this->skillFontDirectory()
                : $this->skillExampleDirectory();
        }
        return $kind === self::KIND_FONT
            ? $this->principalFontDirectory()
            : $this->principalExampleDirectory();
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
