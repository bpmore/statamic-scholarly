<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Notifications;

/**
 * What one notify run did, including who could not be told.
 */
final readonly class NotifyReport
{
    /**
     * @param  int  $sent  Notifications sent
     * @param  int  $stamped  Candidates now marked as mentioned
     * @param  list<ReviewItem>  $unrouted  Researchers with waiting candidates and nobody to tell
     */
    public function __construct(
        public int $sent,
        public int $stamped,
        public array $unrouted,
    ) {}

    public function summary(): string
    {
        $line = \sprintf('%d notification(s) sent covering %d candidate(s)', $this->sent, $this->stamped);

        if ($this->unrouted !== []) {
            $line .= \sprintf(
                '; NOBODY TOLD for %d researcher(s) with no verifier and no fallback: %s',
                \count($this->unrouted),
                \implode(', ', \array_map(static fn (ReviewItem $i): string => \sprintf('%s (%d waiting)', $i->researcherName, $i->waiting), $this->unrouted)),
            );
        }

        return $line;
    }
}
