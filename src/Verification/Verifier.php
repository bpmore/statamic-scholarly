<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Verification;

use Bpmore\Scholarly\Publications\PublicationsCollection;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;

/**
 * The three things a person can do to a candidate, and what each records.
 *
 * Every transition writes who and when. That is not bookkeeping: a
 * publication list is a claim about a named person, and "approved" with no
 * record of who approved it is a claim nobody stands behind.
 *
 * Only entries in the owned collection are touched. Anything else is a
 * programming error and is refused, not quietly "approved".
 */
final class Verifier
{
    public const PERMISSION = 'verify scholarly publications';

    public function __construct(private readonly PublicationsCollection $collection) {}

    /**
     * Approve. Allowed from candidate and from rejected, because a person
     * may reverse another person's decision; the record shows who did.
     */
    public function confirm(Entry $entry, User $by): void
    {
        $this->guard($entry);

        $entry->merge([
            'verification_status' => 'approved',
            'verified_by' => $by->id(),
            'verified_at' => \date(DATE_ATOM),
            'deferred_at' => null,
        ]);
        $entry->save();
    }

    /**
     * Reject. Permanent against re-fetch (the writer never touches a rejected
     * entry) but not against a person: confirm() can reverse it.
     */
    public function reject(Entry $entry, User $by, ?string $reason = null): void
    {
        $this->guard($entry);

        $reason = $reason === null ? null : \trim($reason);

        $entry->merge([
            'verification_status' => 'rejected',
            'verified_by' => $by->id(),
            'verified_at' => \date(DATE_ATOM),
            'rejection_reason' => $reason === '' ? null : $reason,
            'deferred_at' => null,
        ]);
        $entry->save();
    }

    /**
     * Leave it a candidate, out of the current notification batch.
     */
    public function defer(Entry $entry, User $by): void
    {
        $this->guard($entry);

        if ($entry->get('verification_status') !== 'candidate') {
            throw new InvalidArgumentException('Only a candidate can be deferred.');
        }

        $entry->set('deferred_at', \date(DATE_ATOM));
        $entry->save();
    }

    public function isPublication(mixed $item): bool
    {
        return $item instanceof Entry && $item->collectionHandle() === $this->collection->handle();
    }

    private function guard(Entry $entry): void
    {
        if (! $this->isPublication($entry)) {
            throw new InvalidArgumentException(\sprintf(
                'Entry %s is in collection "%s", not the publications collection "%s".',
                $entry->id(),
                $entry->collectionHandle(),
                $this->collection->handle(),
            ));
        }
    }
}
