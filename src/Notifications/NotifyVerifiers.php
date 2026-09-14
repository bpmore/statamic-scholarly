<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Notifications;

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Mails whoever verifies for each researcher about candidates not yet
 * mentioned to anyone.
 *
 * Driven by state, not by a run: it reads candidates with no `notified_at`
 * and stamps them once sent. That makes it safe to run after a queued fetch
 * finishes, on a schedule, or by hand, and it never sends the same
 * candidate twice. Deferred candidates are left out, which is what Defer
 * is for, and so are candidates set aside below the discard floor.
 *
 * The recipient is the researcher's `scholarly_verifier`, then the
 * configured fallback address. A researcher with neither is NOT silently
 * skipped: the run reports them, because "nobody was told" is the quiet
 * failure this whole addon refuses.
 */
final class NotifyVerifiers
{
    public const VERIFIER_FIELD = 'scholarly_verifier';

    public function __construct(
        private readonly PublicationsCollection $collection,
        private readonly bool $digest = true,
        private readonly ?string $fallbackEmail = null,
        private readonly array $channels = ['mail'],
        private readonly ?string $reviewUrl = null,
    ) {}

    public function __invoke(): NotifyReport
    {
        $candidates = Entry::query()
            ->where('collection', $this->collection->handle())
            ->where('verification_status', 'candidate')
            ->whereNull('notified_at')
            ->whereNull('deferred_at')
            ->whereNull('discarded_at')
            ->get();

        /** @var array<string, int> $waitingByResearcher */
        $waitingByResearcher = [];

        foreach ($candidates as $candidate) {
            foreach ((array) $candidate->get('researchers', []) as $researcherId) {
                if (\is_string($researcherId) || \is_int($researcherId)) {
                    $waitingByResearcher[(string) $researcherId] = ($waitingByResearcher[(string) $researcherId] ?? 0) + 1;
                }
            }
        }

        /** @var array<string, array{recipient: mixed, items: list<ReviewItem>}> $batches */
        $batches = [];
        $unrouted = [];

        foreach ($waitingByResearcher as $researcherId => $waiting) {
            // Array keys: PHP turns a numeric-string id into an int.
            $researcherId = (string) $researcherId;
            $researcher = Entry::find($researcherId);

            if ($researcher === null) {
                continue;
            }

            $lastRun = (array) $researcher->get(FetchForResearcher::LAST_RUN_FIELD, []);

            $item = new ReviewItem(
                $researcherId,
                (string) ($researcher->get('title') ?? $researcherId),
                $waiting,
                (bool) ($lastRun['complete'] ?? true),
                \is_string($lastRun['summary'] ?? null) ? $lastRun['summary'] : null,
            );

            [$key, $recipient] = $this->recipientFor($researcher);

            if ($recipient === null) {
                $unrouted[] = $item;

                continue;
            }

            // A digest groups by recipient; otherwise each researcher is a
            // mail of their own, keyed so two never merge.
            $batchKey = $this->digest ? $key : $key.'|'.$researcherId;

            $batches[$batchKey] ??= ['recipient' => $recipient, 'items' => []];
            $batches[$batchKey]['items'][] = $item;
        }

        $sent = 0;

        foreach ($batches as $batch) {
            $batch['recipient']->notify(new CandidatesAwaitingReview($batch['items'], $this->reviewUrl(), $this->channels));
            $sent++;
        }

        $stampedAt = \date(DATE_ATOM);
        $stamped = 0;

        foreach ($candidates as $candidate) {
            if ($this->wasMentioned($candidate, $batches)) {
                $candidate->set('notified_at', $stampedAt);
                $candidate->saveQuietly();
                $stamped++;
            }
        }

        if ($unrouted !== []) {
            Log::warning('Scholarly has candidates waiting for researchers with no verifier and no fallback address. Nobody was told.', [
                'researchers' => \array_map(static fn (ReviewItem $i): string => $i->researcherName, $unrouted),
            ]);
        }

        return new NotifyReport($sent, $stamped, $unrouted);
    }

    /**
     * @return array{0: string, 1: mixed} A grouping key and something with notify(), or null
     */
    private function recipientFor(EntryContract $researcher): array
    {
        $verifier = $researcher->get(self::VERIFIER_FIELD);

        if (\is_array($verifier)) {
            $verifier = $verifier[0] ?? null;
        }

        if (\is_string($verifier) && $verifier !== '' && ($user = User::find($verifier)) !== null) {
            return ['user:'.$user->id(), $user];
        }

        $fallback = $this->fallbackEmail === null ? '' : \trim($this->fallbackEmail);

        if ($fallback !== '') {
            return ['mail:'.$fallback, Notification::route('mail', $fallback)];
        }

        return ['none', null];
    }

    /**
     * Only a candidate someone was actually told about is stamped. One whose
     * every researcher was unrouted stays unstamped, so it is still waiting
     * when a verifier is set.
     *
     * @param  array<string, array{recipient: mixed, items: list<ReviewItem>}>  $batches
     */
    private function wasMentioned(EntryContract $candidate, array $batches): bool
    {
        $mentioned = [];

        foreach ($batches as $batch) {
            foreach ($batch['items'] as $item) {
                $mentioned[$item->researcherId] = true;
            }
        }

        foreach ((array) $candidate->get('researchers', []) as $researcherId) {
            if (isset($mentioned[(string) $researcherId])) {
                return true;
            }
        }

        return false;
    }

    private function reviewUrl(): string
    {
        return $this->reviewUrl ?? \cp_route('collections.show', $this->collection->handle());
    }
}
