<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Publications;

/**
 * One real paper, assembled from every candidate that proved to be it.
 */
final readonly class MergedPublication
{
    /**
     * @param  list<CandidatePublication>  $candidates  In the order they arrived
     * @param  list<string>  $sourceKeys  Distinct sources that reported it
     * @param  list<array{0: CandidatePublication, 1: CandidatePublication}>  $titleMatches
     *                                                                       Pairs matched on title alone, for review rather than merging
     */
    public function __construct(
        public array $candidates,
        public array $sourceKeys,
        public ?string $doi = null,
        public ?string $pmid = null,
        public array $titleMatches = [],
    ) {}

    /**
     * How many distinct sources independently reported this paper.
     *
     * Corroboration is a scoring signal in its own right: a paper three sources
     * agree on is far more likely to be real than one reported once.
     */
    public function corroborationCount(): int
    {
        return \count($this->sourceKeys);
    }

    public function title(): ?string
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->title !== null && \trim($candidate->title) !== '') {
                return $candidate->title;
            }
        }

        return null;
    }
}
