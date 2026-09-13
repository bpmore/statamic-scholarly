<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

use Bpmore\Scholarly\Publications\MergedPublication;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Publications\Snapshot;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Writes merged publications for one researcher into the owned collection.
 *
 * One entry per (paper, researcher). The claim the addon makes is "this
 * paper is this person's work", and a verifier's decision is about that pair,
 * so the status lives on the pair. See DECISIONS for the alternative.
 *
 * What happens to an entry that already exists depends on its status, and
 * the rules protect a person's work from the machine:
 *
 *   rejected   Untouched. A source returning the same namesake paper nightly
 *              must not re-queue it, or the verifier's rejection meant nothing.
 *   approved   Bibliographic fields untouched, because "edit and confirm"
 *              corrects the snapshot and a refresh would undo the correction.
 *              Only identifiers and the source list are added to, since
 *              adding an identifier never destroys an edit.
 *   candidate  Snapshot rewritten. Nobody has touched it yet.
 */
final class PublicationWriter
{
    public function __construct(private readonly PublicationsCollection $collection) {}

    /**
     * @param  list<MergedPublication>  $merged
     */
    public function write(EntryContract $researcher, array $merged, string $fetchedAt): WriteReport
    {
        $created = 0;
        $refreshed = 0;
        $kept = 0;
        $suppressed = 0;

        /** @var array<int, EntryContract> $entriesByIndex */
        $entriesByIndex = [];

        foreach ($merged as $index => $publication) {
            $values = Snapshot::fromMerged($publication);
            $existing = $this->find($researcher, $publication);

            if ($existing === null) {
                $entry = $this->create($researcher, $values, $fetchedAt);
                $created++;
            } else {
                $entry = $existing;

                switch ($existing->get('verification_status')) {
                    case 'rejected':
                        $suppressed++;

                        continue 2;

                    case 'approved':
                        $this->addIdentifiers($existing, $values, $fetchedAt);
                        $kept++;

                        break;

                    default:
                        $this->refresh($existing, $values, $fetchedAt);
                        $refreshed++;
                }
            }

            $entriesByIndex[$index] = $entry;
        }

        $this->linkTitleMatches($merged, $entriesByIndex);

        return new WriteReport($created, $refreshed, $kept, $suppressed);
    }

    /**
     * This researcher's entry for this paper, matched on DOI then PMID.
     *
     * Title is never a match key here either, for the same reason it is not
     * one in the de-duplicator.
     */
    private function find(EntryContract $researcher, MergedPublication $publication): ?EntryContract
    {
        foreach (['doi' => $publication->doi, 'pmid' => $publication->pmid] as $field => $value) {
            if ($value === null) {
                continue;
            }

            $entry = Entry::query()
                ->where('collection', $this->collection->handle())
                ->where('researcher', $researcher->id())
                ->where($field, $value)
                ->first();

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(EntryContract $researcher, array $values, string $fetchedAt): EntryContract
    {
        // The id doubles as the slug. A slug from the title would collide
        // between two researchers who share a paper, and Statamic writes the
        // file by slug, so a collision is a silent overwrite.
        $id = (string) Str::uuid();

        $entry = Entry::make()
            ->collection($this->collection->handle())
            ->locale(Site::default()->handle())
            ->id($id)
            ->slug($id)
            ->published(true)
            ->data([
                ...$values,
                'researcher' => $researcher->id(),
                'verification_status' => 'candidate',
                'fetched_at' => $fetchedAt,
            ]);

        $entry->saveQuietly();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function refresh(EntryContract $entry, array $values, string $fetchedAt): void
    {
        $entry->merge([...$values, 'fetched_at' => $fetchedAt]);
        $entry->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function addIdentifiers(EntryContract $entry, array $values, string $fetchedAt): void
    {
        $sources = (array) $entry->get('source_apis', []);

        foreach ($values['source_apis'] as $key) {
            if (! \in_array($key, $sources, true)) {
                $sources[] = $key;
            }
        }

        $entry->merge([
            'doi' => $entry->get('doi') ?? $values['doi'],
            'pmid' => $entry->get('pmid') ?? $values['pmid'],
            'source_apis' => $sources,
            'fetched_at' => $fetchedAt,
        ]);
        $entry->saveQuietly();
    }

    /**
     * Point each title-matched entry at the others, so the verifier sees the
     * pair. Nothing is merged.
     *
     * @param  list<MergedPublication>  $merged
     * @param  array<int, EntryContract>  $entriesByIndex
     */
    private function linkTitleMatches(array $merged, array $entriesByIndex): void
    {
        foreach ($merged as $index => $publication) {
            if ($publication->titleMatches === [] || ! isset($entriesByIndex[$index])) {
                continue;
            }

            $ids = [];

            foreach ($merged as $otherIndex => $other) {
                if ($otherIndex === $index || ! isset($entriesByIndex[$otherIndex])) {
                    continue;
                }

                foreach ($publication->titleMatches as [$a, $b]) {
                    if (\in_array($b, $other->candidates, true) || \in_array($a, $other->candidates, true)) {
                        $ids[] = $entriesByIndex[$otherIndex]->id();

                        break;
                    }
                }
            }

            if ($ids === []) {
                continue;
            }

            $entry = $entriesByIndex[$index];
            $entry->set('possible_duplicates', \array_values(\array_unique([...(array) $entry->get('possible_duplicates', []), ...$ids])));
            $entry->saveQuietly();
        }
    }
}
