<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use RuntimeException;

/**
 * Thrown when a fetch is attempted with no contact email configured.
 *
 * Deliberately fatal rather than a warning. NCBI's usage guidelines ask every
 * client to identify itself so they can make contact before blocking a heavy
 * one. An addon installed on many sites that skips this makes every one of
 * those sites anonymous traffic from NCBI's side, and the block, when it comes,
 * lands on site owners who did nothing.
 *
 * Refusing is the safe direction: a run that did not happen is visible, and one
 * that happened anonymously is not.
 */
final class MissingContactEmail extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Scholarly will not query NCBI without a contact email. Set SCHOLARLY_NCBI_EMAIL, '
            .'or scholarly.ncbi.email, to an address NCBI can reach if this site sends too much '
            .'traffic. See https://www.ncbi.nlm.nih.gov/books/NBK25497/'
        );
    }
}
