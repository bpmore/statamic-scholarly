<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

/**
 * One researcher's fetch, end to end: what each source said, and what was
 * written.
 *
 * The report is the product's promise in one object. A source that could not
 * run is in `outcomes` as a failure; a source that was not configured is in
 * `skipped`; and `isComplete()` is false if either list is non-empty, so a
 * caller cannot render the result as an answer without noticing.
 */
final readonly class RunReport
{
    /**
     * @param  array<string, FetchOutcome>  $outcomes  Keyed by source key, in the order run
     * @param  list<string>  $skipped  Sources that reported themselves unconfigured
     */
    public function __construct(
        public string $researcherId,
        public array $outcomes,
        public array $skipped,
        public WriteReport $written,
        public int $titleMatches = 0,
        public int $autoApproved = 0,
        public int $setAside = 0,
    ) {}

    /**
     * Every selected source was configured and completed.
     *
     * A source that was not configured makes the run partial too. It does
     * not fail the run, but "we did not look there" is part of the answer.
     */
    public function isComplete(): bool
    {
        if ($this->skipped !== []) {
            return false;
        }

        foreach ($this->outcomes as $outcome) {
            if (! $outcome->isComplete()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<SourceFailure>
     */
    public function failures(): array
    {
        $failures = [];

        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->failures as $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * Sources that completed, whose last-fetched stamp may advance.
     *
     * @return list<string>
     */
    public function sourcesRun(): array
    {
        $run = [];

        foreach ($this->outcomes as $outcome) {
            foreach ($outcome->sourcesRun as $key) {
                $run[] = $key;
            }
        }

        return $run;
    }

    public function candidateCount(): int
    {
        $count = 0;

        foreach ($this->outcomes as $outcome) {
            $count += \count($outcome->candidates);
        }

        return $count;
    }

    public function summary(): string
    {
        $parts = [\sprintf(
            '%s: %d candidate(s) from %s; %s',
            $this->isComplete() ? 'complete' : 'PARTIAL',
            $this->candidateCount(),
            $this->sourcesRun() === [] ? 'none' : \implode(', ', $this->sourcesRun()),
            $this->written->summary(),
        )];

        if ($this->titleMatches > 0) {
            $parts[] = \sprintf('%d title match(es) flagged for review', $this->titleMatches);
        }

        if ($this->autoApproved > 0) {
            $parts[] = \sprintf('%d approved on score', $this->autoApproved);
        }

        if ($this->setAside > 0) {
            $parts[] = \sprintf('%d set aside on score', $this->setAside);
        }

        foreach ($this->failures() as $failure) {
            $parts[] = 'failed '.$failure->describe();
        }

        if ($this->skipped !== []) {
            $parts[] = 'not configured: '.\implode(', ', $this->skipped);
        }

        return \implode('; ', $parts);
    }
}
