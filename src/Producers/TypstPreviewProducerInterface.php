<?php

declare(strict_types=1);

namespace Spora\Plugins\Typst\Producers;

use Spora\Services\MediaArchive\DerivativeOutput;

/**
 * Ephemeral-render surface for {@see \Spora\Plugins\Typst\Http\TypstPreviewController}.
 *
 * Why a dedicated interface: {@see TypstRenderProducer} is `final`
 * (per the project's architecture rule that producers don't subclass)
 * so Mockery can't mock it for tests. The preview controller talks to
 * this interface instead, the production path's
 * {@see TypstRenderProducer::produceFromString()} satisfies it, and
 * tests can pass any object with the matching shape.
 *
 * Distinct from {@see \Spora\Services\MediaArchive\MediaDerivativeProducerInterface}
 * because the preview surface has no MediaAsset in scope — taking a
 * raw source string is the whole point.
 */
interface TypstPreviewProducerInterface
{
    /**
     * Compile + render the given source to `$format` without
     * persisting anything. `$principalId` scopes `template_dir` /
     * `font_dirs` in the same way {@see TypstRenderProducer::produce()}
     * scopes them from a `MediaAsset`.
     *
     * @param array<string, mixed> $options
     */
    public function produceFromString(string $source, string $format, ?int $principalId, array $options = []): DerivativeOutput;
}
