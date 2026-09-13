<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

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
 *   politely still exceed the ceiling together.
 */
final class EsummaryFetcher implements PublicationMetadataFetcher
{
    /**
     * Bumped when the cached shape changes, so an old entry cannot be read as
     * a new one.
     */
    private const string CACHE_PREFIX = 'scholarly_esummary_v1:';

    private const string LIMITER_KEY = 'scholarly:ncbi';

    /** How long to sleep between checks of the shared limiter. */
    private const int POLL_MICROSECONDS = 50_000;

    /** Upper bound on waiting, so a stuck window cannot hang a worker. */
    private const int MAX_WAIT_MICROSECONDS = 5_000_000;

    public function __construct(
        private readonly EutilsRequest $request,
        private readonly int $cacheTtlSeconds = 604_800,
        private readonly int $timeoutSeconds = 10,
    ) {}

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
        $this->waitForSlot();

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

    /**
     * Block until this process may send another request.
     *
     * `RateLimiter::attempt()` does NOT wait: it runs the callback or returns
     * false. Used alone it would drop a request rather than pace it, which on a
     * queued job means a silently missing publication. So this polls instead.
     *
     * The limiter lives in the cache, so the ceiling is shared across every
     * worker and command rather than being per process. That is the whole
     * point: two workers each politely pacing themselves still exceed NCBI's
     * limit together.
     *
     * Bounded rather than an open loop. If the window never clears, something
     * other than ordinary contention is wrong, and hanging a worker forever is
     * not the way to report it.
     */
    private function waitForSlot(): void
    {
        $perSecond = $this->request->ratePerSecond();

        for ($waited = 0; $waited < self::MAX_WAIT_MICROSECONDS; $waited += self::POLL_MICROSECONDS) {
            if (! RateLimiter::tooManyAttempts(self::LIMITER_KEY, $perSecond)) {
                RateLimiter::hit(self::LIMITER_KEY, 1);

                return;
            }

            \usleep(self::POLL_MICROSECONDS);
        }

        Log::warning('Scholarly waited out its NCBI rate-limit window and sent anyway.', [
            'per_second' => $perSecond,
        ]);

        RateLimiter::hit(self::LIMITER_KEY, 1);
    }
}
