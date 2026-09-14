<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Commands;

use Bpmore\Scholarly\Import\BibtexParser;
use Bpmore\Scholarly\Import\CsvParser;
use Bpmore\Scholarly\Import\ImportPublications;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * `scholarly:import`: a BibTeX or CSV file of publications for one
 * researcher. The supported route for Google Scholar (export, do not
 * scrape) and for hand-kept lists.
 */
final class Import extends Command
{
    use RunsInPlease;

    protected $signature = 'scholarly:import
        {file : Path to a .bib or .csv file}
        {researcher : An entry id or slug in the researcher collection}
        {--format= : bibtex or csv; guessed from the extension when omitted}
        {--confirm-as= : Email or id of the person confirming these as the researcher\'s work; otherwise they queue for review}';

    protected $description = 'Import publications for a researcher from a BibTeX or CSV file';

    public function handle(ImportPublications $import): int
    {
        $path = (string) $this->argument('file');

        if (! \is_readable($path)) {
            $this->error(\sprintf('Cannot read "%s".', $path));

            return self::INVALID;
        }

        $researcher = $this->researcher((string) $this->argument('researcher'));

        if ($researcher === null) {
            $this->error(\sprintf('No researcher "%s" in collection "%s".', $this->argument('researcher'), config('statamic-scholarly.researchers.collection')));

            return self::INVALID;
        }

        $confirmAs = null;

        if (($who = $this->option('confirm-as')) !== null && $who !== '') {
            $confirmAs = User::find($who) ?? User::findByEmail($who);

            if ($confirmAs === null) {
                $this->error(\sprintf('No user "%s" to confirm as.', $who));

                return self::INVALID;
            }
        }

        $format = \strtolower((string) ($this->option('format') ?: \pathinfo($path, PATHINFO_EXTENSION)));
        $contents = (string) \file_get_contents($path);

        try {
            $parsed = match ($format) {
                'bib', 'bibtex' => BibtexParser::parse($contents),
                'csv', 'tsv' => CsvParser::parse($contents),
                default => throw new \InvalidArgumentException(\sprintf('Unknown format "%s": use --format=bibtex or --format=csv.', $format)),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        foreach ($parsed['skipped'] as $reason) {
            $this->warn('Skipped '.$reason);
        }

        if ($parsed['candidates'] === []) {
            $this->error('Nothing in the file could be imported.');

            return self::FAILURE;
        }

        $result = $import($researcher, $parsed['candidates'], $confirmAs);

        $this->info(\sprintf(
            '%s: %d record(s) read, %d skipped; %s%s',
            $researcher->get('title') ?? $researcher->id(),
            \count($parsed['candidates']),
            \count($parsed['skipped']),
            $result['written']->summary(),
            $result['confirmed'] > 0 ? \sprintf('; %d confirmed as %s', $result['confirmed'], $confirmAs?->email()) : '',
        ));

        return self::SUCCESS;
    }

    private function researcher(string $argument): ?\Statamic\Contracts\Entries\Entry
    {
        $collection = (string) config('statamic-scholarly.researchers.collection');

        $entry = Entry::find($argument)
            ?? Entry::query()->where('collection', $collection)->where('slug', $argument)->first();

        return $entry === null || $entry->collectionHandle() !== $collection ? null : $entry;
    }
}
