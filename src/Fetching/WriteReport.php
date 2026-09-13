<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

/**
 * What writing one run's merged publications did to the entries.
 *
 * Every merged publication lands in exactly one bucket, so the four counts
 * sum to the number written, and a reader can see at a glance that a run
 * which found forty papers and created none was forty already-known ones
 * rather than a silent failure to write.
 */
final readonly class WriteReport
{
    /**
     * @param  int  $created  New candidate entries
     * @param  int  $refreshed  Existing candidates whose snapshot was rewritten
     * @param  int  $keptApproved  Approved entries left as the verifier had them
     * @param  int  $suppressed  Rejected entries the source returned again
     */
    public function __construct(
        public int $created = 0,
        public int $refreshed = 0,
        public int $keptApproved = 0,
        public int $suppressed = 0,
    ) {}

    public function total(): int
    {
        return $this->created + $this->refreshed + $this->keptApproved + $this->suppressed;
    }

    public function summary(): string
    {
        return \sprintf(
            '%d created, %d refreshed, %d approved kept, %d rejected suppressed',
            $this->created,
            $this->refreshed,
            $this->keptApproved,
            $this->suppressed,
        );
    }
}
