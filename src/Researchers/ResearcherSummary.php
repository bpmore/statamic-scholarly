<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Researchers;

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Publications\ApprovedPublications;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * What the researcher's own entry page shows about their publications,
 * written as plain fields so Statamic's own fieldtypes render it.
 *
 * No fieldtype of this addon's, and nothing computed on render: the
 * values are written when they change (after every scoring pass, which
 * follows every fetch, decision, import and calibration) and read like any
 * other field. A control-panel listing of a thousand researchers costs
 * nothing extra.
 *
 * The fields live in a fieldset `scholarly:install` writes for the host to
 * import into its researcher blueprint. Handles are prefixed so they cannot
 * collide with the host's own.
 */
final class ResearcherSummary
{
    public const RECENT_FIELD = 'scholarly_recent_publications';

    public const WAITING_FIELD = 'scholarly_candidates_waiting';

    public const STATUS_FIELD = 'scholarly_status';

    public const FIELDSET = 'scholarly';

    /** How many approved publications the entry page lists. */
    public const RECENT_LIMIT = 10;

    public function __construct(
        private readonly ApprovedPublications $approved,
        private readonly PublicationsCollection $collection,
    ) {}

    /**
     * @return bool Whether anything changed
     */
    public function refresh(EntryContract $researcher): bool
    {
        $id = (string) $researcher->id();

        $recent = $this->approved->query($id)
            ->orderBy('publication_date', 'desc')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (EntryContract $e): string => (string) $e->id())
            ->values()
            ->all();

        $waiting = Entry::query()
            ->where('collection', $this->collection->handle())
            ->where('verification_status', 'candidate')
            ->whereNull('discarded_at')
            ->get()
            ->filter(fn (EntryContract $e): bool => \in_array($id, \array_map('strval', (array) $e->get('researchers', [])), true))
            ->count();

        $lastRun = (array) $researcher->get(FetchForResearcher::LAST_RUN_FIELD, []);
        $status = \is_string($lastRun['summary'] ?? null)
            ? \sprintf('%s: %s', \is_string($lastRun['at'] ?? null) ? \substr($lastRun['at'], 0, 16) : 'last run', $lastRun['summary'])
            : null;

        $current = [
            self::RECENT_FIELD => \array_map('strval', (array) $researcher->get(self::RECENT_FIELD, [])),
            self::WAITING_FIELD => $researcher->get(self::WAITING_FIELD),
            self::STATUS_FIELD => $researcher->get(self::STATUS_FIELD),
        ];

        $next = [
            self::RECENT_FIELD => $recent,
            self::WAITING_FIELD => $waiting,
            self::STATUS_FIELD => $status,
        ];

        if ($current == $next) {
            return false;
        }

        $researcher->merge($next);
        $researcher->saveQuietly();

        return true;
    }

    /**
     * The fieldset a host imports into its researcher blueprint.
     *
     * @return array<string, mixed>
     */
    public function fieldsetContents(): array
    {
        $field = static fn (string $handle, array $config): array => ['handle' => $handle, 'field' => $config];

        return [
            'title' => 'Scholarly',
            'fields' => [
                $field('scholarly_verifier', ['type' => 'users', 'display' => 'Publication verifier', 'max_items' => 1, 'instructions' => 'Who is mailed when candidate publications are waiting for this researcher.']),
                $field(self::RECENT_FIELD, ['type' => 'entries', 'display' => 'Recent approved publications', 'collections' => [$this->collection->handle()], 'read_only' => true, 'instructions' => \sprintf('The %d newest approved publications. Written by the addon; edit the publications, not this list.', self::RECENT_LIMIT)]),
                $field(self::WAITING_FIELD, ['type' => 'integer', 'display' => 'Candidates waiting', 'read_only' => true, 'instructions' => 'Candidates not yet confirmed, rejected or set aside. Review them under Publication review.']),
                $field(self::STATUS_FIELD, ['type' => 'text', 'display' => 'Last fetch', 'read_only' => true, 'instructions' => 'PARTIAL means a source could not answer; the list may be incomplete.']),
            ],
        ];
    }
}
