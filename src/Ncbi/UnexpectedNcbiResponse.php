<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use RuntimeException;

/**
 * NCBI answered in a shape this version does not understand.
 *
 * A renamed field in E-utilities would otherwise read as an empty result,
 * which is one of the five things zero publications must never silently mean.
 */
final class UnexpectedNcbiResponse extends RuntimeException
{
    public static function missing(string $what, string $endpoint): self
    {
        return new self(\sprintf('NCBI %s response is missing %s', $endpoint, $what));
    }
}
