<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Writes a confidence score and its breakdown onto every candidate of one
 * researcher.
 *
 * Three things are written, and the third is the one that keeps the first
 * honest: `confidence_score`, `confidence_breakdown`, and
 * `confidence_state`, which says whether the number is `certain` (an
 * anchor, not a score), `uncalibrated` (the prior; an ordering, not a
 * probability) or `calibrated` (fitted to this site's decisions). Copy
 * and labels read the state before they read the number.
 *
 * Only candidates are scored. An approved or rejected entry is a person's
 * decision, and a number next to it would look like a second opinion.
 */
final class ScoreCandidates
{
    public const PREVALENCE_FIELD = 'scholarly_name_prevalence';

    /**
     * @param  list<string>  $affiliationPatterns
     */
    public function __construct(
        private readonly PublicationsCollection $collection,
        private readonly FingerprintBuilder $fingerprints,
        private readonly Model $model,
        private readonly array $affiliationPatterns = [],
    ) {}

    /**
     * @return int Candidates scored
     */
    public function forResearcher(EntryContract $researcher): int
    {
        $fingerprint = $this->fingerprints->build($researcher);
        $prevalence = $researcher->get(self::PREVALENCE_FIELD);
        $prevalence = \is_int($prevalence) || (\is_string($prevalence) && \ctype_digit($prevalence)) ? (int) $prevalence : null;
        $id = (string) $researcher->id();

        $candidates = Entry::query()
            ->where('collection', $this->collection->handle())
            ->where('verification_status', 'candidate')
            ->get()
            ->filter(fn (EntryContract $e): bool => \in_array($id, \array_map('strval', (array) $e->get('researchers', [])), true));

        $scored = 0;

        foreach ($candidates as $entry) {
            $this->score($entry, $fingerprint, $prevalence);
            $scored++;
        }

        return $scored;
    }

    private function score(EntryContract $entry, Fingerprint $fingerprint, ?int $prevalence): void
    {
        $sources = \array_values(\array_filter((array) $entry->get('source_apis', []), 'is_string'));

        $candidate = new CandidatePublication(
            sourceKey: \in_array('orcid', $sources, true) ? 'orcid' : ($sources[0] ?? 'unknown'),
            title: \is_string($entry->get('title')) ? $entry->get('title') : null,
            journal: \is_string($entry->get('journal')) ? $entry->get('journal') : null,
            authors: FingerprintBuilder::authorsOf($entry),
        );

        $signals = Signals::compute($candidate, $fingerprint, $this->affiliationPatterns, $prevalence, \count($sources));

        if ($signals->anchored) {
            $score = 1.0;
            $state = 'certain';
            $contributions = [];
        } else {
            $score = $this->model->score($signals);
            $state = $this->model->state();
            $contributions = $this->model->contributions($signals);
        }

        $breakdown = [];

        foreach ($signals->toArray() as $key => $value) {
            $breakdown['signal_'.$key] = \is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? 'unknown');
        }

        foreach ($contributions as $key => $value) {
            $breakdown['weight_'.$key] = (string) \round($value, 3);
        }

        $breakdown['fingerprint_papers'] = (string) $fingerprint->certainPapers;

        $entry->merge([
            'confidence_score' => $score,
            'confidence_state' => $state,
            'confidence_breakdown' => $breakdown,
            'scored_at' => \date(DATE_ATOM),
        ]);
        $entry->saveQuietly();
    }
}
