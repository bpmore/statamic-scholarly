<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Commands;

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Fetching\SourceRegistry;
use Bpmore\Scholarly\Jobs\FetchPublications;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * `scholarly:fetch`: queue a fetch for every researcher, or run one now.
 *
 * `--sync` runs in this process and prints each report, which is how a
 * person checks a new site's configuration before trusting the schedule.
 */
final class Fetch extends Command
{
    use RunsInPlease;

    protected $signature = 'scholarly:fetch
        {researcher? : An entry id or slug in the researcher collection; every researcher when omitted}
        {--source= : One source key (orcid, pubmed); every source when omitted}
        {--since= : Override the per-source last-fetched stamp with this date}
        {--sync : Run now in this process instead of queueing}';

    protected $description = 'Fetch publications for researchers, de-duplicate them, and queue candidates for review';

    public function handle(FetchForResearcher $fetch, SourceRegistry $sources): int
    {
        $sourceKey = $this->option('source') ?: null;
        $since = $this->option('since') ?: null;

        try {
            $sources->select($sourceKey);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $researchers = $this->researchers();

        if ($researchers === []) {
            $this->error(\sprintf(
                'No researcher found. Collection "%s" is empty or the argument matched nothing.',
                (string) config('statamic-scholarly.researchers.collection'),
            ));

            return self::INVALID;
        }

        $partial = 0;

        foreach ($researchers as $researcher) {
            if (! $this->option('sync')) {
                FetchPublications::dispatch($researcher->id(), $sourceKey, $since);

                continue;
            }

            $report = $fetch($researcher, $sourceKey, $since);
            $line = \sprintf('%s: %s', $researcher->get('title') ?? $researcher->id(), $report->summary());

            if ($report->isComplete()) {
                $this->info($line);
            } else {
                $this->warn($line);
                $partial++;
            }
        }

        if (! $this->option('sync')) {
            $this->info(\sprintf('Queued %d fetch job(s).', \count($researchers)));

            return self::SUCCESS;
        }

        if ($partial > 0) {
            $this->warn(\sprintf('%d of %d run(s) were partial. See each line above for the source that could not answer.', $partial, \count($researchers)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<EntryContract>
     */
    private function researchers(): array
    {
        $collection = (string) config('statamic-scholarly.researchers.collection');
        $argument = $this->argument('researcher');

        if ($argument === null) {
            return Entry::query()->where('collection', $collection)->get()->all();
        }

        $entry = Entry::find($argument)
            ?? Entry::query()->where('collection', $collection)->where('slug', $argument)->first();

        return $entry === null || $entry->collectionHandle() !== $collection ? [] : [$entry];
    }
}
