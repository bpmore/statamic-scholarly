<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Bpmore\Scholarly\Fetching\SharedRateLimit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The addon's own E-summary client.
 *
 * Three properties it must keep, each of which has bitten a real codebase:
 *
 *   It NEVER runs on a render path. Every caller is a queued job or a console
 *   command. A computed field that fetches turns a control-panel listing into
 *   a serialised run of network calls.
 *
 *   It caches misses as well as hits, under a versioned prefix. A PMID that
 *   does not exist is otherwise re-fetched on every single call, forever.
 *   But only a miss NCBI actually reported. A timeout is not a miss, and an
 *   earlier version of this class cached one as such for a week.
 *
 *   It rate limits CENTRALLY, not per call site. Sleeping between requests
 *   inside one method is not a rate limit: two queue workers each doing that
 *   politely still exceed the ceiling together. See SharedRateLimit.
 */
final class EsummaryFetcher implements PublicationMetadataFetcher
{
    /**
     * Bumped when the cached shape changes, so an old entry cannot be read as
     * a new one.
     */
    private const CACHE_PREFIX = 'scholarly_esummary_v1:';

    /** Shared by every NCBI caller on the site: the ceiling is per site, not per source. */
    public const LIMITER_KEY = 'scholarly:ncbi';

    /**
     * PMIDs per esummary request. NCBI accepts far more by POST; this keeps a
     * GET URL short and one failed request small.
     */
    public const BATCH_SIZE = 100;

    private readonly SharedRateLimit $limiter;

    public function __construct(
        private readonly EutilsRequest $request,
        private readonly int $cacheTtlSeconds = 604_800,
        private readonly int $timeoutSeconds = 10,
    ) {
        $this->limiter = new SharedRateLimit(self::LIMITER_KEY, $request->ratePerSecond());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summaryForPmid(string $pmid): ?array
    {
        return $this->summariesForPmids([$pmid])[$pmid] ?? null;
    }

    /**
     * @param  list<string>  $pmids
     * @return array<int|string, array<string, mixed>|null>
     */
    public function summariesForPmids(array $pmids): array
    {
        $pmids = \array_values(\array_unique($pmids));
        $out = [];
        $toFetch = [];

        foreach ($pmids as $pmid) {
            $cached = Cache::get(self::CACHE_PREFIX.$pmid);

            // A cached `false` is a recorded miss. Distinguished from a cache
            // miss (null) so a known-absent record is not re-fetched every time.
            if ($cached === false) {
                $out[$pmid] = null;
            } elseif (\is_array($cached)) {
                $out[$pmid] = $cached;
            } else {
                $toFetch[] = $pmid;
            }
        }

        foreach (\array_chunk($toFetch, self::BATCH_SIZE) as $chunk) {
            foreach ($this->fetch($chunk) as $pmid => $row) {
                Cache::put(self::CACHE_PREFIX.$pmid, $row ?? false, $this->cacheTtlSeconds);
                $out[$pmid] = $row;
            }
        }

        // Back in the order asked for, so a caller iterating the result sees
        // search order rather than cache-hit order.
        $ordered = [];

        foreach ($pmids as $pmid) {
            $ordered[$pmid] = $out[$pmid];
        }

        return $ordered;
    }

    /**
     * One request for one chunk. Every PMID asked for comes back as a key.
     *
     * @param  list<string>  $pmids
     * @return array<string, array<string, mixed>|null>
     *
     * @throws NcbiUnavailable
     * @throws UnexpectedNcbiResponse
     */
    private function fetch(array $pmids): array
    {
        $this->limiter->waitForSlot();

        try {
            $response = Http::timeout($this->timeoutSeconds)->get(
                EutilsRequest::BASE.'esummary.fcgi',
                $this->request->query(['db' => 'pubmed', 'retmode' => 'json', 'id' => \implode(',', $pmids)]),
            );
        } catch (ConnectionException $e) {
            throw new NcbiUnavailable($e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw NcbiUnavailable::status($response->status(), 'esummary');
        }

        $decoded = $response->json();
        $result = \is_array($decoded) ? ($decoded['result'] ?? null) : null;

        if (! \is_array($result)) {
            throw UnexpectedNcbiResponse::missing('"result"', 'esummary');
        }

        $rows = [];

        foreach ($pmids as $pmid) {
            $row = $result[$pmid] ?? null;

            if (! \is_array($row)) {
                // NCBI reports an unknown id as a row carrying "error", so a
                // PMID with no row at all is a shape this version has not seen.
                throw UnexpectedNcbiResponse::missing(\sprintf('a row for PMID %s', $pmid), 'esummary');
            }

            $rows[$pmid] = isset($row['error']) ? null : $row;
        }

        return $rows;
    }
}
