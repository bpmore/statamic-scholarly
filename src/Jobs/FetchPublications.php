<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Jobs;

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

/**
 * Fetch for one researcher, on the queue.
 *
 * One job per researcher rather than one per source: de-duplication needs
 * every source's candidates in one place, and a paper ORCID and PubMed both
 * report must become one entry, not two that a later pass has to find.
 *
 * Carries the researcher's id, not the entry. An entry serialised into the
 * queue is a snapshot that may be stale by the time the job runs.
 */
final class FetchPublications implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $researcherId,
        public readonly ?string $sourceKey = null,
        public readonly ?string $since = null,
    ) {}

    public function handle(FetchForResearcher $fetch): void
    {
        $researcher = Entry::find($this->researcherId);

        if ($researcher === null) {
            // Not an exception: a researcher deleted between dispatch and run
            // is not a failure of the run, and retrying will not find them.
            Log::warning('Scholarly skipped a fetch for a researcher that no longer exists.', [
                'researcher' => $this->researcherId,
            ]);

            return;
        }

        $fetch($researcher, $this->sourceKey, $this->since);
    }
}
