<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Services;

use RuntimeException;

/**
 * High-level CRUD over tier-2 (principal) resources: list, write, delete.
 *
 * Reads are delegated to {@see TypstResourcePaths::listBasenames()} so
 * tier-1 + tier-2 are visible together. Writes only ever touch tier 2,
 * and only ever under the principal id this store was constructed
 * with — there is no operator-wide tier-2 directory by design.
 *
 * `write()` validates the basename and bytes length before touching
 * disk: a 50 MB arbitrary upload from the admin panel would otherwise
 * land in `<storage>/typst/fonts/1/` and start costing the operator
 * real money. The limits are conservative on purpose; operators with
 * legitimate larger needs can bump them per-deploy.
 */
final class TypstResourceStore
{
    /**
     * Max bytes for a single upload. ~5 MB is enough for a full OTF
     * font (Inter-Regular is ~610 KB) and for reasonably-sized Typst
     * examples. Operators wanting larger fonts can bump this in a
     * follow-up; we don't want a single request to fill the storage
     * volume before the admin notices.
     */
    public const MAX_BYTES = 5_242_880;

    /**
     * Basenames restricted to a conservative charset: `A-Z a-z 0-9 . _ -`.
     * Traversal segments (`/`), path separators (`\`), and shell metas
     * are all rejected — the basename is concatenated into a path that
     * PHP's filesystem layer trusts unconditionally.
     */
    private const BASENAME_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function __construct(
        private readonly TypstResourcePaths $paths,
    ) {}

    /**
     * @return list<array{name: string, kind: string, origin: string, size: int, modified_at: int}>
     */
    public function list(string $kind): array
    {
        $out = [];
        foreach ($this->paths->listBasenames($kind) as $basename) {
            $tierOne = $this->paths->tierOnePath($kind, $basename);
            $tierTwo = $this->paths->tierTwoPath($kind, $basename);
            $effective = is_file($tierTwo) ? $tierTwo : $tierOne;
            $stat = @stat($effective);
            $out[] = [
                'name'        => $basename,
                'kind'        => $kind,
                'origin'      => is_file($tierTwo) ? 'principal' : 'skill',
                'size'        => is_int($stat['size'] ?? null) ? $stat['size'] : 0,
                'modified_at' => is_int($stat['mtime'] ?? null) ? $stat['mtime'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Persist a new tier-2 resource or overwrite an existing one.
     * Returns the absolute path that was written so the caller can
     * echo it back in API responses.
     */
    public function write(string $kind, string $basename, string $bytes): string
    {
        $this->validateBasename($basename);
        $this->validateBytes($bytes);

        $path = $this->paths->tierTwoPath($kind, $basename);
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf(
                'TypstResourceStore: could not create directory "%s"',
                $dir,
            ));
        }

        $written = @file_put_contents($path, $bytes);
        if ($written === false || $written !== strlen($bytes)) {
            throw new RuntimeException(sprintf(
                'TypstResourceStore: failed to write "%s"',
                $path,
            ));
        }
        return $path;
    }

    /**
     * Remove a tier-2 resource. Throws when the resource is tier-1
     * only (built-in, read-only) so a stray DELETE call can't strip a
     * plugin-shipped font or example.
     */
    public function delete(string $kind, string $basename): void
    {
        $this->validateBasename($basename);
        $path = $this->paths->tierTwoPath($kind, $basename);
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'TypstResourceStore: resource "%s" is not writable (built-in or missing)',
                $basename,
            ));
        }
        if (!@unlink($path)) {
            throw new RuntimeException(sprintf(
                'TypstResourceStore: failed to delete "%s"',
                $path,
            ));
        }
    }

    /**
     * Bytes of a resource visible to this principal, regardless of tier.
     * Returns null when neither tier has the basename.
     */
    public function read(string $kind, string $basename): ?string
    {
        $tierTwo = $this->paths->tierTwoPath($kind, $basename);
        if (is_file($tierTwo)) {
            $bytes = @file_get_contents($tierTwo);
            return $bytes === false ? null : $bytes;
        }
        $tierOne = $this->paths->tierOnePath($kind, $basename);
        if (is_file($tierOne)) {
            $bytes = @file_get_contents($tierOne);
            return $bytes === false ? null : $bytes;
        }
        return null;
    }

    private function validateBasename(string $basename): void
    {
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new RuntimeException('TypstResourceStore: empty or reserved basename');
        }
        if (!preg_match(self::BASENAME_PATTERN, $basename)) {
            throw new RuntimeException(sprintf(
                'TypstResourceStore: invalid basename "%s" (allowed: A-Z a-z 0-9 . _ -)',
                $basename,
            ));
        }
        if (strlen($basename) > 128) {
            throw new RuntimeException('TypstResourceStore: basename exceeds 128 characters');
        }
    }

    private function validateBytes(string $bytes): void
    {
        if ($bytes === '') {
            throw new RuntimeException('TypstResourceStore: empty payload');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'TypstResourceStore: payload exceeds %d bytes',
                self::MAX_BYTES,
            ));
        }
    }
}
