<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Bpmore\Scholarly\Fetching\SharedRateLimit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    private const string CACHE_PREFIX = 'scholarly_esummary_v1:';

    /** Shared by every NCBI caller on the site: the ceiling is per site, not per source. */
    public const string LIMITER_KEY = 'scholarly:ncbi';

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
        $key = self::CACHE_PREFIX.$pmid;

        $cached = Cache::get($key);

        // A cached `false` is a recorded miss. Distinguished from a cache miss
        // (null) so a known-absent record is not re-fetched every time.
        if ($cached === false) {
            return null;
        }

        if (\is_array($cached)) {
            return $cached;
        }

        $row = $this->fetch($pmid);

        Cache::put($key, $row ?? false, $this->cacheTtlSeconds);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $pmid): ?array
    {
        $this->limiter->waitForSlot();

        try {
            $response = Http::timeout($this->timeoutSeconds)->get(
                EutilsRequest::BASE.'esummary.fcgi',
                $this->request->query(['db' => 'pubmed', 'retmode' => 'json', 'id' => $pmid]),
            );
        } catch (ConnectionException $e) {
            // Logged rather than swallowed: a transport failure that looks like
            // an empty result is the failure this addon exists to refuse.
            Log::warning('Scholarly could not reach NCBI for a PMID.', [
                'pmid' => $pmid,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Scholarly got an unsuccessful response from NCBI.', [
                'pmid' => $pmid,
                'status' => $response->status(),
            ]);

            return null;
        }

        $decoded = $response->json();

        if (! \is_array($decoded)) {
            return null;
        }

        $row = $decoded['result'][$pmid] ?? null;

        return \is_array($row) ? $row : null;
    }
}
