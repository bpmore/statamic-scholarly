<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use RuntimeException;

/**
 * NCBI could not be asked, or refused: a timeout, a rate-limit block, an
 * outage. Retrying later may work.
 *
 * Thrown rather than returned as null because null already has a meaning at
 * the metadata seam: NCBI positively said there is no such record. Folding a
 * timeout into that answer is how a rate-limit block gets cached as "no such
 * paper" for a week.
 */
final class NcbiUnavailable extends RuntimeException
{
    public static function status(int $status, string $endpoint): self
    {
        return new self(\sprintf('HTTP %d from NCBI %s', $status, $endpoint));
    }
}
