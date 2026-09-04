<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Converters;

use Spora\Services\MediaArchive\MediaConverterInterface;

/**
 * Pass-through converter for Typst source files (`text/x-typst` / `.typ`).
 *
 * Typst source is UTF-8 text the LLM can read directly \u2014 there is
 * nothing to parse, tokenize, or strip. The converter returns the
 * uploaded bytes as-is (trimmed) so they end up in `markdown_content`
 * alongside other text uploads. The downstream agent can then either
 * call {@see \Spora\Plugins\Typst\Tools\TypstCompileTool} to compile
 * the source or read it as a reference document.
 *
 * Registered in {@see \Spora\Plugins\Typst\TypstPlugin::register()}
 * via {@see \Spora\Services\MediaArchive\MediaConverterDiscovery::add()}.
 * The registration pulls `text/x-typst` into the upload allowlist at
 * request time (the converter-supplied branch of
 * {@see \Spora\Services\MediaArchive\MediaAllowedTypesService::allowedMimeTypes()}).
 *
 * The companion {@see \Spora\Plugins\Typst\Producers\TypstRenderProducer}
 * handles the other half \u2014 turning an uploaded `.typ` into PDF / PNG /
 * SVG derivatives via the media archive's "Convert to" dropdown. Both
 * pieces are required to make `.typ` a fully-supported upload target.
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
