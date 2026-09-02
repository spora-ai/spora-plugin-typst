<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Http;

use Spora\Plugins\Typst\Services\TypstResourcePaths;

/**
 * CRUD for principal-tier **templates** — full document skeletons
 * the LLM (or operator) fills in.
 *
 * URL prefix: `/api/v1/typst/templates`
 *
 * Wire shape:
 *   GET    /                → `{ data: { templates: [...] } }`
 *   POST   /                → `{ data: { template: {...} } }` (201)
 *   GET    /{name}          → text/plain, the source bytes
 *   DELETE /{name}          → 204
 */
final class TypstTemplateController extends AbstractTypstTextResourceController
{
    protected function kind(): string
    {
        return TypstResourcePaths::KIND_TEMPLATE;
    }

    protected function pluralName(): string
    {
        return 'templates';
    }

    protected function singularName(): string
    {
        return 'template';
    }
}
