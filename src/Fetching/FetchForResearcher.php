<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\Deduplicator;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * One researcher, every selected source, de-duplicated, written, stamped.
 *
 * Never runs on a render path: the job and the command are its only callers.
 *
 * The last-fetched stamp is per source and advances only for a source that
 * completed. A source that failed keeps its old stamp, so the next run asks
 * it for the same window again rather than for a window that skips whatever
 * it could not fetch this time. The stamp is the time the run STARTED, not
 * ended, for the same reason at a finer grain.
 */
final class FetchForResearcher
{
    public const LAST_FETCHED_FIELD = 'scholarly_last_fetched';

    /**
     * The last run's verdict, on the researcher, so a notification and the
     * control panel can say "partial" without re-reading the log.
     */
    public const LAST_RUN_FIELD = 'scholarly_last_run';

    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly Deduplicator $deduplicator,
        private readonly PublicationWriter $writer,
        private readonly PublicationsCollection $collection,
    ) {}

    /**
     * @param  string|null  $sourceKey  One source, or every source
     * @param  string|null  $since  Overrides the per-source stamp for this run
     */
    public function __invoke(EntryContract $researcher, ?string $sourceKey = null, ?string $since = null): RunReport
    {
        $this->collection->ensure();

        $startedAt = \date(DATE_ATOM);
        $stamps = (array) $researcher->get(self::LAST_FETCHED_FIELD, []);

        /** @var array<string, FetchOutcome> $outcomes */
        $outcomes = [];
        $skipped = [];

        /** @var list<CandidatePublication> $candidates */
        $candidates = [];

        foreach ($this->sources->select($sourceKey) as $source) {
            $key = $source->sourceKey();

            if (! $source->isConfigured()) {
                $skipped[] = $key;

                continue;
            }

            $window = $since ?? (\is_string($stamps[$key] ?? null) ? $stamps[$key] : null);

            $outcomes[$key] = $source->fetchForResearcher($researcher, $window);
            $candidates = [...$candidates, ...$outcomes[$key]->candidates];
        }

        $merged = $this->deduplicator->merge($candidates);
        $written = $this->writer->write($researcher, $merged, $startedAt);

        $report = new RunReport((string) $researcher->id(), $outcomes, $skipped, $written, $written->titleMatched);

        $this->stamp($researcher, $stamps, $report, $startedAt);
        $this->log($researcher, $report);

        return $report;
    }

    /**
     * The per-source stamps advance only for sources that completed. The
     * last-run record is written every time, partial or not: a partial run
     * that left no trace on the researcher would be the silent failure.
     *
     * @param  array<string, mixed>  $stamps
     */
    private function stamp(EntryContract $researcher, array $stamps, RunReport $report, string $startedAt): void
    {
        foreach ($report->sourcesRun() as $key) {
            $stamps[$key] = $startedAt;
        }

        $researcher->merge([
            self::LAST_FETCHED_FIELD => $stamps,
            self::LAST_RUN_FIELD => [
                'at' => $startedAt,
                'complete' => $report->isComplete(),
                'summary' => $report->summary(),
            ],
        ]);
        $researcher->saveQuietly();
    }

    private function log(EntryContract $researcher, RunReport $report): void
    {
        $context = ['researcher' => (string) $researcher->id(), 'report' => $report->summary()];

        if ($report->isComplete()) {
            Log::info('Scholarly fetched publications.', $context);

            return;
        }

        // Warning, not info: a partial run rendered as complete is the
        // failure this addon exists to refuse, and a log level is the first
        // place a reader filters on.
        Log::warning('Scholarly fetch was partial.', $context);
    }
}
