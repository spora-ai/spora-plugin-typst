<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Converters;

use Spora\Services\MediaArchive\MediaConverterInterface;

/**
 * Pass-through converter for Typst source files (`text/x-typst` / `.typ`).
 *
 * Typst source is UTF-8 text the LLM reads directly — no parsing is required.
 * Registration adds `text/x-typst` to the plugin-supplied upload allowlist;
 * rendering is handled by {@see \Spora\Plugins\Typst\Producers\TypstRenderProducer}.
 */
final class TypstSourcePassthroughConverter implements MediaConverterInterface
{
    /** @var list<string> */
    private const SUPPORTED_MIME_TYPES = [
        'text/x-typst',
    ];

    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = [
        'typ',
    ];

    /** @return list<string> */
    public function supportedMimeTypes(): array
    {
        return self::SUPPORTED_MIME_TYPES;
    }

    /** @return list<string> */
    public function supportedExtensions(): array
    {
        return self::SUPPORTED_EXTENSIONS;
    }

    public function toMarkdown(string $bytes, string $mime, ?string $filename = null): string
    {
        return trim($bytes);
    }
}
