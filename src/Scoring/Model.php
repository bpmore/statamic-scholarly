<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * Turns signals into a number between 0 and 1, and says what kind of
 * number it is.
 */
interface Model
{
    /** `uncalibrated` for a prior, `calibrated` once fitted to this site's decisions. */
    public function state(): string;

    public function score(Signals $signals): float;

    /**
     * @return array<string, float> Per-term contributions, for the breakdown
     */
    public function contributions(Signals $signals): array;
}
