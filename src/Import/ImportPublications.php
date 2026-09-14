<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Import;

use Bpmore\Scholarly\Fetching\PublicationWriter;
use Bpmore\Scholarly\Fetching\WriteReport;
use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\Deduplicator;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Scoring\ScoreCandidates;
use Bpmore\Scholarly\Verification\Verifier;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Takes a file's worth of candidates through the same path a fetch takes:
 * de-duplicated against each other, matched against what the collection
 * already holds, written as candidates, scored.
 *
 * "Import" is a source like any other, with one difference: a person chose
 * these. When that person is the researcher (their own reference manager
 * export), the caller may confirm them on the way in, recorded as that
 * person's decision. Otherwise they queue like everything else, because a
 * list somebody compiled about a namesake is the same list.
 */
final class ImportPublications
{
    public function __construct(
        private readonly Deduplicator $deduplicator,
        private readonly PublicationWriter $writer,
        private readonly PublicationsCollection $collection,
        private readonly ScoreCandidates $scorer,
        private readonly Verifier $verifier,
    ) {}

    /**
     * @param  list<CandidatePublication>  $candidates
     * @return array{written: WriteReport, confirmed: int}
     */
    public function __invoke(EntryContract $researcher, array $candidates, ?User $confirmAs = null): array
    {
        $this->collection->ensure();

        $now = \date(DATE_ATOM);
        $merged = $this->deduplicator->merge($candidates);
        $written = $this->writer->write($researcher, $merged, $now);
        $confirmed = 0;

        if ($confirmAs !== null) {
            // Only what this import created or refreshed as a candidate.
            // An entry a person already rejected stays rejected, and one
            // already approved needs no second approval.
            foreach ($written->candidateIds as $id) {
                $entry = Entry::find($id);

                if ($entry !== null && $entry->get('verification_status') === 'candidate') {
                    $this->verifier->confirm($entry, $confirmAs);
                    $confirmed++;
                }
            }
        }

        $this->scorer->forResearcher(Entry::find($researcher->id()) ?? $researcher);

        return ['written' => $written, 'confirmed' => $confirmed];
    }
}
