<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * One decision a person made, as the model sees it: the feature vector the
 * candidate had, and whether the person said yes.
 */
final readonly class Example
{
    /**
     * @param  array<string, float>  $vector  Features::vector() of the signals
     */
    public function __construct(
        public array $vector,
        public bool $positive,
    ) {}
}
