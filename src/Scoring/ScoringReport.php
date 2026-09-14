<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * What one scoring pass did for one researcher.
 */
final readonly class ScoringReport
{
    public function __construct(
        public int $scored,
        public int $autoApproved,
        public int $setAside = 0,
    ) {}
}
