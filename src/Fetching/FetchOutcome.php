<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

use Bpmore\Scholarly\Publications\CandidatePublication;

/**
 * What one fetch run found, and what it could not look at.
 *
 * The second half is the point. An outcome carrying candidates and no failures
 * is an answer; an outcome carrying the same candidates and two failures is a
 * partial answer, and a caller that renders them identically has published a
 * claim it cannot support.
 */
final readonly class FetchOutcome
{
    /**
     * @param  list<CandidatePublication>  $candidates
     * @param  list<SourceFailure>  $failures
     * @param  list<string>  $sourcesRun  Keys of sources that completed
     */
    /**
     * @param  array<string, scalar>  $notes  Facts a source learned on the way, keyed by name (e.g. `name_prevalence`)
     */
    public function __construct(
        public array $candidates = [],
        public array $failures = [],
        public array $sourcesRun = [],
        public array $notes = [],
    ) {}

    /**
     * True when every configured source completed.
     *
     * Callers render a complete and a partial run differently. Nothing in this
     * class decides how; it only refuses to let them be confused.
     */
    public function isComplete(): bool
    {
        return $this->failures === [];
    }

    /**
     * True when nothing ran at all, which is the case most easily mistaken for
     * "this researcher has no publications".
     */
    public function isEmptyBecauseNothingRan(): bool
    {
        return $this->sourcesRun === [] && $this->failures !== [];
    }

    /**
     * A single line safe to put in a log or a notification.
     */
    public function summary(): string
    {
        $ran = $this->sourcesRun === [] ? 'none' : \implode(', ', $this->sourcesRun);

        if ($this->isComplete()) {
            return \sprintf('%d candidate(s) from %s', \count($this->candidates), $ran);
        }

        return \sprintf(
            'PARTIAL: %d candidate(s) from %s; %d source(s) failed: %s',
            \count($this->candidates),
            $ran,
            \count($this->failures),
            \implode('; ', \array_map(static fn (SourceFailure $f): string => $f->describe(), $this->failures)),
        );
    }

    public function withCandidates(CandidatePublication ...$candidates): self
    {
        return new self(
            [...$this->candidates, ...\array_values($candidates)],
            $this->failures,
            $this->sourcesRun,
            $this->notes,
        );
    }

    public function withFailure(SourceFailure $failure): self
    {
        return new self($this->candidates, [...$this->failures, $failure], $this->sourcesRun, $this->notes);
    }

    public function withSourceRun(string $sourceKey): self
    {
        return new self($this->candidates, $this->failures, [...$this->sourcesRun, $sourceKey], $this->notes);
    }

    /**
     * A fact learned on the way that is not a candidate: how many records a
     * name matched, for one. Kept off the candidates so it cannot be
     * mistaken for one.
     */
    public function withNote(string $key, int|float|string|bool $value): self
    {
        return new self($this->candidates, $this->failures, $this->sourcesRun, [...$this->notes, $key => $value]);
    }
}
