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
 * One entry per paper, with a `researchers` list, because papers are
 * co-authored and a shared paper is one record. The status is the entry's,
 * so attaching a researcher to an entry is a claim about them, and the
 * rules below make sure a machine never completes that claim on its own:
 *
 *   rejected   Untouched, and no researcher is added. A source returning the
 *              same namesake paper nightly must not re-queue it, or the
 *              verifier's rejection meant nothing. A verifier can attach a
 *              co-author by hand.
 *   approved   Bibliographic fields untouched, because "edit and confirm"
 *              corrects the snapshot and a refresh would undo the correction.
 *              Identifiers and the source list are added to. If the fetch is
 *              for a researcher NOT yet listed, they are added and the entry
 *              goes back to candidate: an approval was a person's decision
 *              about the listed names, and a PubMed name match could attach
 *              a namesake to an approved paper otherwise.
 *   candidate  Snapshot rewritten, researcher added. Nobody has touched it.
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
        $reopened = 0;

        /** @var array<int, EntryContract> $entriesByIndex */
        $entriesByIndex = [];

        foreach ($merged as $index => $publication) {
            $values = Snapshot::fromMerged($publication);
            $existing = $this->find($publication);

            if ($existing === null) {
                $entry = $this->create($researcher, $values, $fetchedAt);
                $created++;
            } else {
                $entry = $existing;
                $listed = \in_array((string) $researcher->id(), $this->researchersOn($existing), true);

                switch ($existing->get('verification_status')) {
                    case 'rejected':
                        $suppressed++;

                        continue 2;

                    case 'approved':
                        $this->addIdentifiers($existing, $values, $fetchedAt);

                        if ($listed) {
                            $kept++;
                        } else {
                            $this->reopenFor($existing, $researcher);
                            $reopened++;
                        }

                        break;

                    default:
                        $this->refresh($existing, $researcher, $values, $fetchedAt);
                        $refreshed++;
                }
            }

            $entriesByIndex[$index] = $entry;
        }

        $this->linkTitleMatches($merged, $entriesByIndex);

        return new WriteReport($created, $refreshed, $kept, $suppressed, $reopened);
    }

    /**
     * The site's entry for this paper, whoever it was fetched for, matched
     * on DOI then PMID.
     *
     * Title is never a match key here either, for the same reason it is not
     * one in the de-duplicator.
     */
    private function find(MergedPublication $publication): ?EntryContract
    {
        foreach (['doi' => $publication->doi, 'pmid' => $publication->pmid] as $field => $value) {
            if ($value === null) {
                continue;
            }

            $entry = Entry::query()
                ->where('collection', $this->collection->handle())
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
        // The slug is random rather than from the title: titles collide (an
        // erratum and its paper), and the Stache writes the file by slug, so
        // a title slug is a silent overwrite waiting to happen. The id is
        // left to the driver: the Stache assigns a uuid, and the Eloquent
        // driver assigns an integer or a uuid depending on the host's model,
        // and an id set here would fight the integer one.
        $entry = Entry::make()
            ->collection($this->collection->handle())
            ->locale(Site::default()->handle())
            ->slug((string) Str::uuid())
            ->published(true)
            ->data([
                ...$values,
                'researchers' => [(string) $researcher->id()],
                'verification_status' => 'candidate',
                'fetched_at' => $fetchedAt,
            ]);

        $entry->saveQuietly();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function refresh(EntryContract $entry, EntryContract $researcher, array $values, string $fetchedAt): void
    {
        $entry->merge([
            ...$values,
            'researchers' => $this->withResearcher($entry, $researcher),
            'fetched_at' => $fetchedAt,
        ]);
        $entry->saveQuietly();
    }

    /**
     * A person approved this entry for the names on it. A new name is a new
     * claim, so the entry goes back to the queue with the new name attached.
     * The earlier verifier and time are kept as history.
     */
    private function reopenFor(EntryContract $entry, EntryContract $researcher): void
    {
        $entry->merge([
            'researchers' => $this->withResearcher($entry, $researcher),
            'verification_status' => 'candidate',
        ]);
        $entry->saveQuietly();
    }

    /**
     * Ids are kept as strings whatever the driver hands out, so a strict
     * comparison between a stored id and a live one cannot fail on type.
     *
     * @return list<string>
     */
    private function researchersOn(EntryContract $entry): array
    {
        $ids = [];

        foreach ((array) $entry->get('researchers', []) as $id) {
            if (\is_string($id) || \is_int($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function withResearcher(EntryContract $entry, EntryContract $researcher): array
    {
        $ids = $this->researchersOn($entry);

        if (! \in_array((string) $researcher->id(), $ids, true)) {
            $ids[] = (string) $researcher->id();
        }

        return $ids;
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
                        $ids[] = (string) $entriesByIndex[$otherIndex]->id();

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
