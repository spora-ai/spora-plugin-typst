<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Spora\Plugins\Typst\Services\TypstResourcePaths;

/**
 * CRUD for principal-tier **examples** — pattern snippets the LLM
 * reads to learn a single Typst primitive (headings, tables, etc.).
 *
 * URL prefix: `/api/v1/typst/examples`
 *
 * Wire shape: identical to {@see TypstTemplateController} but with
 * `examples` / `example` envelope keys.
 */
final class TypstExampleController extends AbstractTypstTextResourceController
{
    protected function kind(): string
    {
        return TypstResourcePaths::KIND_EXAMPLE;
    }

    protected function pluralName(): string
    {
        return 'examples';
    }

    protected function singularName(): string
    {
        return 'example';
    }
}
