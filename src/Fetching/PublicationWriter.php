<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

use Bpmore\Scholarly\Publications\MergedPublication;
use Bpmore\Scholarly\Publications\PublicationKey;
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
 *   approved   Fields the entry has are untouched, because "edit and
 *              confirm" corrects the snapshot and a refresh would undo the
 *              correction. Fields it lacks are filled in, and the source
 *              list is added to. If the fetch is
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

        /** @var list<string> $candidateIds */
        $candidateIds = [];

        foreach ($merged as $index => $publication) {
            $values = Snapshot::fromMerged($publication);
            $existing = $this->find($publication);

            if ($existing === null) {
                $entry = $this->create($researcher, $values, $fetchedAt);
                $created++;
                $candidateIds[] = (string) $entry->id();
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
                            $candidateIds[] = (string) $existing->id();
                        }

                        break;

                    default:
                        $this->refresh($existing, $researcher, $values, $fetchedAt);
                        $refreshed++;
                        $candidateIds[] = (string) $existing->id();
                }
            }

            $entriesByIndex[$index] = $entry;
        }

        $titleMatched = $this->linkTitleMatches($entriesByIndex);

        return new WriteReport($created, $refreshed, $kept, $suppressed, $reopened, $titleMatched, $candidateIds);
    }

    /**
     * The site's entry for this paper, whoever it was fetched for, matched
     * on DOI, PMID, then the source's scoped record identity.
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

        foreach (Snapshot::fromMerged($publication)['source_record_ids'] as $id) {
            $entry = Entry::query()
                ->where('collection', $this->collection->handle())
                ->whereJsonContains('source_record_ids', $id)
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
        // A source that did not report a field this time is not saying the
        // field is wrong. Only values the run actually has replace stored
        // ones; a DOI stored last month survives a PMID-only source tonight.
        $values = \array_filter($values, static fn (mixed $v): bool => $v !== null && $v !== []);

        $entry->merge([
            ...$values,
            'source_apis' => $this->unionSources($entry, $values['source_apis'] ?? []),
            'source_record_ids' => $this->unionRecordIds($entry, $values['source_record_ids'] ?? []),
            'researchers' => $this->withResearcher($entry, $researcher),
            'fetched_at' => $fetchedAt,
        ]);
        $entry->saveQuietly();
    }

    /**
     * Every source that has ever reported this paper. Corroboration is a
     * signal in its own right, and a source that reported it last month and
     * not tonight still reported it.
     *
     * @param  list<string>  $reported
     * @return list<string>
     */
    private function unionSources(EntryContract $entry, array $reported): array
    {
        $sources = \array_values(\array_filter((array) $entry->get('source_apis', []), 'is_string'));

        foreach ($reported as $key) {
            if (! \in_array($key, $sources, true)) {
                $sources[] = $key;
            }
        }

        return $sources;
    }

    /** @return list<string> */
    private function unionRecordIds(EntryContract $entry, array $reported): array
    {
        // Retain old aliases when a source changes which record it prefers.
        return \array_values(\array_unique([
            ...(array) $entry->get('source_record_ids', []),
            ...$reported,
        ]));
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
        // Blanks may be filled: a value the entry never had cannot have been
        // a verifier's correction. A value it has is left alone.
        $fill = [];

        foreach (['doi', 'pmid', 'journal', 'publication_date', 'volume', 'issue', 'pages', 'abstract'] as $field) {
            $current = $entry->get($field);

            if (($current === null || $current === '') && ($values[$field] ?? null) !== null) {
                $fill[$field] = $values[$field];
            }
        }

        $entry->merge([
            ...$fill,
            'source_apis' => $this->unionSources($entry, $values['source_apis']),
            'source_record_ids' => $this->unionRecordIds($entry, $values['source_record_ids'] ?? []),
            'fetched_at' => $fetchedAt,
            // From the stored title, which the verifier may have corrected,
            // not from the fetched one.
            'title_key' => PublicationKey::normaliseTitle((string) $entry->get('title')),
        ]);
        $entry->saveQuietly();
    }

    /**
     * Point each entry written this run at every other entry in the
     * collection with the same title and no shared identifier, both ways,
     * so the verifier sees the pair. Nothing is merged: an erratum and the
     * paper it corrects share a title and are different works.
     *
     * Across runs as well as within one, which is why the key is stored on
     * the entry: last month's conference abstract and tonight's full paper
     * are the case that matters, and they never arrive together.
     *
     * @param  array<int, EntryContract>  $entriesByIndex
     * @return int Entries written this run that gained at least one link
     */
    private function linkTitleMatches(array $entriesByIndex): int
    {
        /** @var array<string, true> $written */
        $written = [];

        foreach ($entriesByIndex as $entry) {
            $written[(string) $entry->id()] = true;
        }

        /** @var array<string, true> $touched */
        $touched = [];

        foreach ($entriesByIndex as $entry) {
            $key = $entry->get('title_key');

            if (! \is_string($key) || $key === '') {
                continue;
            }

            $others = Entry::query()
                ->where('collection', $this->collection->handle())
                ->where('title_key', $key)
                ->get()
                ->reject(fn (EntryContract $other): bool => (string) $other->id() === (string) $entry->id())
                ->reject(fn (EntryContract $other): bool => $this->shareAnIdentifier($entry, $other));

            if ($others->isEmpty()) {
                continue;
            }

            $ids = $others->map(fn (EntryContract $o): string => (string) $o->id())->all();

            if ($this->addDuplicates($entry, $ids)) {
                $touched[(string) $entry->id()] = true;
            }

            foreach ($others as $other) {
                if ($this->addDuplicates($other, [(string) $entry->id()])) {
                    $touched[(string) $other->id()] = true;
                }
            }
        }

        return \count(\array_intersect_key($touched, $written));
    }

    /**
     * Two entries with the same DOI or PMID are the same paper and would have
     * been matched by find(); if they exist apart, somebody separated them
     * on purpose, and a title link would only nag.
     */
    private function shareAnIdentifier(EntryContract $a, EntryContract $b): bool
    {
        foreach (['doi', 'pmid'] as $field) {
            $x = $a->get($field);
            $y = $b->get($field);

            if ($x !== null && $x !== '' && (string) $x === (string) $y) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ids
     * @return bool Whether anything new was added
     */
    private function addDuplicates(EntryContract $entry, array $ids): bool
    {
        $current = \array_map('strval', (array) $entry->get('possible_duplicates', []));
        $merged = \array_values(\array_unique([...$current, ...$ids]));

        if ($merged === $current) {
            return false;
        }

        $entry->set('possible_duplicates', $merged);
        $entry->saveQuietly();

        return true;
    }
}
