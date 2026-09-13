<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Contracts;

use Bpmore\Scholarly\Fetching\FetchOutcome;
use Statamic\Contracts\Entries\Entry;

/**
 * One place publications come from.
 *
 * Hosts implement this to add a source the addon does not ship: an internal
 * research information system, a departmental repository, a legacy database.
 *
 * Note the return type. A source hands back a FetchOutcome rather than a bare
 * array, because "found nothing" and "could not look" are different answers and
 * an array cannot tell them apart.
 */
interface PublicationSource
{
    /**
     * Whether this source has everything it needs to run.
     *
     * False must mean a missing credential or a missing identifier, never a
     * transient failure. A source that reports itself unconfigured is skipped
     * quietly; one that reports itself configured and then fails is reported
     * loudly.
     */
    public function isConfigured(): bool;

    /**
     * Fetch for one researcher.
     *
     * Implementations should not throw for an expected remote failure: catch
     * it and return an outcome carrying a SourceFailure, so one dead source
     * does not abort the others.
     */
    public function fetchForResearcher(Entry $researcher, ?string $since = null): FetchOutcome;

    /**
     * Stable key, used in `source_apis`, in failure reports and as the key of
     * the per-source last-fetched map. Changing it orphans stored state.
     */
    public function sourceKey(): string;
}
