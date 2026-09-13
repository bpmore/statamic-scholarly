<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Contracts;

/**
 * Fetches bibliographic metadata for a PMID.
 *
 * Resolved from the container so a host that already operates a PubMed client
 * can bind its own implementation. Without that seam, a host with an existing
 * client ends up with two: the same PMID is fetched twice, cached under two
 * keyspaces, and the two caches can disagree, which surfaces as one paper
 * rendering differently in two places on the same site.
 */
interface PublicationMetadataFetcher
{
    /**
     * @return array<string, mixed>|null The decoded summary, or null when the
     *                                   record is absent or could not be read.
     *                                   Callers must not treat null as "no such
     *                                   paper": see the addon's reporting rules.
     */
    public function summaryForPmid(string $pmid): ?array;
}
