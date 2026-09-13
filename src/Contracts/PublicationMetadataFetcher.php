<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Contracts;

use Bpmore\Scholarly\Ncbi\NcbiUnavailable;
use Bpmore\Scholarly\Ncbi\UnexpectedNcbiResponse;

/**
 * Fetches bibliographic metadata for PMIDs.
 *
 * Resolved from the container so a host that already operates a PubMed client
 * can bind its own implementation. Without that seam, a host with an existing
 * client ends up with two: the same PMID is fetched twice, cached under two
 * keyspaces, and the two caches can disagree, which surfaces as one paper
 * rendering differently in two places on the same site.
 *
 * Null means one thing only: NCBI answered, and said there is no such record.
 * A fetch that could not be made is an exception, never a null, because the
 * caller renders those two differently and cannot if they arrive the same.
 */
interface PublicationMetadataFetcher
{
    /**
     * @return array<string, mixed>|null The decoded summary, or null when NCBI
     *                                   says the record does not exist
     *
     * @throws NcbiUnavailable when NCBI could not be reached or refused
     * @throws UnexpectedNcbiResponse when the answer was not a summary
     */
    public function summaryForPmid(string $pmid): ?array;

    /**
     * Many at once. A researcher with two hundred papers is two hundred
     * requests one at a time, at three a second, and a whole faculty is hours.
     *
     * @param  list<string>  $pmids
     * @return array<int|string, array<string, mixed>|null> Keyed by PMID (PHP
     *                                                      makes numeric keys
     *                                                      ints), every
     *                                                      requested PMID
     *                                                      present, in the
     *                                                      order asked
     *
     * @throws NcbiUnavailable when NCBI could not be reached or refused
     * @throws UnexpectedNcbiResponse when the answer was not a summary
     */
    public function summariesForPmids(array $pmids): array;
}
