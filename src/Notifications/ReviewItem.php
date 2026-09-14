<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Notifications;

/**
 * One researcher's line in a review notification: how many candidates are
 * waiting, and whether the run that found them could look everywhere.
 */
final readonly class ReviewItem
{
    public function __construct(
        public string $researcherId,
        public string $researcherName,
        public int $waiting,
        public bool $lastRunComplete,
        public ?string $lastRunSummary,
    ) {}
}
