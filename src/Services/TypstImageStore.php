<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use Spora\Plugins\Typst\Exceptions\TypstRuntimeException;

/**
 * Filesystem-backed image store for the Typst plugin.
 *
 * Stores uploaded images as plain files under
 * `<storage>/typst/images/<principal>/<basename>`. Replaces the
 * earlier media_assets-backed version (which made the Typst image
 * library show up in the operator's media archive, cluttering it
 * with input material that doesn't belong there).
 *
 * The rendered Typst OUTPUTS (PDF/PNG/SVG) still land in the media
 * archive as proper derivatives via `MediaDerivativeService::create()` —
 * the split is: inputs (this store, filesystem) vs. outputs (media
 * archive). Per the architecture decision in the PR that introduced
 * this design.
 *
 * Listing / serving / deleting all use the filesystem directly; no
 * `media_assets` rows are touched. The image URL is constructed as
 * `/api/v1/typst/images/<basename>` (served by {@see TypstImageController}
 * via `show()`) so Typst's `#image("…")` calls hit the plugin's own
 * route — keeping the URL surface stable across reinstalls and
 * avoiding the media-archive URL prefix.
 */
final class TypstImageStore
{
    /**
     * Maximum upload size (5 MiB). Same as the font/template cap for
     * plugin-wide simplicity; a single chat-time upload shouldn't
     * fill the storage volume before the admin notices.
     */
    public const MAX_BYTES = 5_242_880;

    private const MIME_PNG = 'image/png';
    private const MIME_JPEG = 'image/jpeg';
    private const MIME_WEBP = 'image/webp';
    private const MIME_SVG = 'image/svg+xml';

    /**
     * Whitelisted image MIMEs. ext-typst accepts PNG / JPEG / WebP via
     * the `#image()` builtin; SVG is allowed because Typst can embed
     * vector graphics (and Spora's chat UI sanitiser handles inline
     * SVG via the same `<img src>` path as raster).
     */
    public const ALLOWED_MIMES = [
        self::MIME_PNG,
        self::MIME_JPEG,
        self::MIME_WEBP,
        self::MIME_SVG,
    ];

    public const MIME_TO_EXT = [
        self::MIME_PNG => 'png',
        self::MIME_JPEG => 'jpg',
        self::MIME_WEBP => 'webp',
        self::MIME_SVG => 'svg',
    ];

    public const EXT_TO_MIME = [
        'png'  => self::MIME_PNG,
        'jpg'  => self::MIME_JPEG,
        'jpeg' => self::MIME_JPEG,
        'webp' => self::MIME_WEBP,
        'svg'  => self::MIME_SVG,
    ];

    /**
     * Basename charset: conservative — `A-Z a-z 0-9 . _ -` only.
     * Path separators and shell metas are rejected; the basename is
     * concatenated into a path PHP's filesystem layer trusts
     * unconditionally.
     */
    private const BASENAME_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function __construct(
        private readonly TypstResourcePaths $paths,
    ) {}

    /**
     * @return list<array{name: string, mime: string, size: int, modified_at: int}>
     */
    public function list(): array
    {
        $dir = $this->paths->principalImageDirectory();
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
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            // The principal root is shared with templates/, examples/,
            // and fonts/ subdirs (see TypstResourcePaths). Only surface
            // files with an image extension so the listing doesn't
            // include `.typ` source files or font binaries.
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!isset(self::EXT_TO_MIME[$ext])) {
                continue;
            }
            $stat = @stat($path);
            $out[] = [
                'name'        => $name,
                'mime'        => self::EXT_TO_MIME[$ext],
                'size'        => is_int($stat['size'] ?? null) ? $stat['size'] : 0,
                'modified_at' => is_int($stat['mtime'] ?? null) ? $stat['mtime'] : 0,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Read raw bytes by basename. Returns null when not present.
     */
    public function read(string $basename): ?string
    {
        $this->validateBasename($basename);
        $path = $this->paths->principalImageDirectory() . '/' . $basename;
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Persist a new image (or overwrite an existing one) and return
     * metadata for the API response. The basename is derived from the
     * supplied `$filename` (sanitised) or, when absent, generated
     * from the MIME type and timestamp.
     */
    public function write(string $bytes, string $mime, ?string $filename = null): array
    {
        $mime = strtolower(trim($mime));
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new TypstRuntimeException(sprintf(
                'TypstImageStore: mime "%s" is not allowed (allowed: %s)',
                $mime,
                implode(', ', self::ALLOWED_MIMES),
            ));
        }
        if ($bytes === '') {
            throw new TypstRuntimeException('TypstImageStore: empty payload');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new TypstRuntimeException(sprintf(
                'TypstImageStore: payload exceeds %d bytes',
                self::MAX_BYTES,
            ));
        }

        $basename = $this->resolveBasename($filename, $mime);

        $dir = $this->paths->principalImageDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new TypstRuntimeException(sprintf(
                'TypstImageStore: could not create directory "%s"',
                $dir,
            ));
        }
        $path = $dir . '/' . $basename;
        $written = @file_put_contents($path, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            throw new TypstRuntimeException(sprintf(
                'TypstImageStore: failed to write "%s"',
                $path,
            ));
        }

        $stat = @stat($path);
        return [
            'name'        => $basename,
            'mime'        => $mime,
            'size'        => strlen($bytes),
            'modified_at' => is_int($stat['mtime'] ?? null) ? $stat['mtime'] : 0,
            'path'        => $path,
        ];
    }

    public function delete(string $basename): void
    {
        $this->validateBasename($basename);
        $path = $this->paths->principalImageDirectory() . '/' . $basename;
        if (!is_file($path)) {
            throw new TypstRuntimeException('TypstImageStore: image not found');
        }
        if (!@unlink($path)) {
            throw new TypstRuntimeException(sprintf(
                'TypstImageStore: failed to delete "%s"',
                $path,
            ));
        }
    }

    /**
     * Build the public URL the LLM (and the playground result panel)
     * paste into `#image("…")`. The plugin's own controller serves
     * these URLs — the chat's `MediaEmbed` markdown links to them as
     * well so the same URL works in both contexts.
     */
    public function publicUrl(string $basename): string
    {
        return '/api/v1/typst/images/' . rawurlencode($basename);
    }

    public static function isAllowedMime(string $mime): bool
    {
        return in_array(strtolower(trim($mime)), self::ALLOWED_MIMES, true);
    }

    /**
     * Sanitise the supplied filename down to the allowed charset,
     * falling back to `<timestamp>.<ext>` when nothing usable came in.
     */
    private function resolveBasename(?string $filename, string $mime): string
    {
        $ext = self::MIME_TO_EXT[$mime] ?? 'bin';
        if ($filename !== null && $filename !== '') {
            $clean = basename(str_replace('\\', '/', $filename));
            if ($clean !== '' && $clean !== '.' && $clean !== '..' && preg_match(self::BASENAME_PATTERN, $clean)) {
                return $clean;
            }
        }
        return sprintf('typst-image-%d.%s', time(), $ext);
    }

    private function validateBasename(string $basename): void
    {
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new TypstRuntimeException('TypstImageStore: empty or reserved basename');
        }
        if (!preg_match(self::BASENAME_PATTERN, $basename)) {
            throw new TypstRuntimeException(sprintf(
                'TypstImageStore: invalid basename "%s" (allowed: A-Z a-z 0-9 . _ -)',
                $basename,
            ));
        }
    }
}
