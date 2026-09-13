<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A request ceiling shared across every worker and command on the site.
 *
 * The limiter lives in the cache, so the ceiling is per site rather than per
 * process. That is the whole point: two queue workers each politely pacing
 * themselves still exceed a remote's limit together, and the block lands on
 * the site owner.
 *
 * `RateLimiter::attempt()` does NOT wait: it runs the callback or returns
 * false. Used alone it would drop a request rather than pace it, which on a
 * queued job means a silently missing publication. So this polls instead.
 *
 * Waiting is bounded. If the window never clears, something other than
 * ordinary contention is wrong, and hanging a worker forever is not the way
 * to report it.
 */
final readonly class SharedRateLimit
{
    /** How long to sleep between checks of the shared limiter. */
    private const int POLL_MICROSECONDS = 50_000;

    /** Upper bound on waiting, so a stuck window cannot hang a worker. */
    private const int MAX_WAIT_MICROSECONDS = 5_000_000;

    /**
     * @param  string  $key  One key per remote. Two sources sharing a remote share a key.
     * @param  int  $perSecond  Requests the remote permits per second
     */
    public function __construct(
        private string $key,
        private int $perSecond,
    ) {}

    /**
     * Block until this process may send another request.
     */
    public function waitForSlot(): void
    {
        for ($waited = 0; $waited < self::MAX_WAIT_MICROSECONDS; $waited += self::POLL_MICROSECONDS) {
            if (! RateLimiter::tooManyAttempts($this->key, $this->perSecond)) {
                RateLimiter::hit($this->key, 1);

                return;
            }

            \usleep(self::POLL_MICROSECONDS);
        }

        Log::warning('Scholarly waited out a rate-limit window and sent anyway.', [
            'limiter' => $this->key,
            'per_second' => $this->perSecond,
        ]);

        RateLimiter::hit($this->key, 1);
    }
}
