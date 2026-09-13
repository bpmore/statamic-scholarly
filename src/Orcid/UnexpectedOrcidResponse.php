<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Orcid;

use RuntimeException;

/**
 * Thrown when ORCID answered in a shape this version does not understand.
 *
 * A parser that quietly returned an empty list here would be reporting "no
 * works" for a researcher whose record it could not read.
 */
final class UnexpectedOrcidResponse extends RuntimeException
{
    public static function missing(string $what): self
    {
        return new self(\sprintf('ORCID response is missing %s', $what));
    }
}
