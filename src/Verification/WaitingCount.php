<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Verification;

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Statamic\Facades\Entry;

/**
 * How many candidates a verifier would see: not confirmed, not rejected,
 * not set aside. Shown on the "Publication review" nav item, which is the
 * nearest thing to a bell Statamic's own navigation offers.
 *
 * One count query, no network. Called once per control-panel request for
 * users who can verify, and not at all for anyone else.
 */
final class WaitingCount
{
    public function __construct(private readonly PublicationsCollection $collection) {}

    public function __invoke(): int
    {
        return Entry::query()
            ->where('collection', $this->collection->handle())
            ->where('verification_status', 'candidate')
            ->whereNull('discarded_at')
            ->count();
    }
}
