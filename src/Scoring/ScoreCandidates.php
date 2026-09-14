<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Researchers\ResearcherSummary;
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
 *
 * A candidate at or above the auto-approve threshold is approved here, if
 * the site has that switched on and the number is one that means
 * something (see approves()). One below the discard floor is set aside:
 * kept, not mailed, filterable, never deleted (see setsAside()).
 */
final class ScoreCandidates
{
    public const PREVALENCE_FIELD = 'scholarly_name_prevalence';

    /**
     * @param  list<string>  $affiliationPatterns
     * @param  float|null  $autoApproveAt  Approve at or above this, or null to never approve on a score
     * @param  float|null  $setAsideBelow  Set aside (keep, but stop asking about) below this, or null to never
     */
    public function __construct(
        private readonly PublicationsCollection $collection,
        private readonly FingerprintBuilder $fingerprints,
        private readonly Model $model,
        private readonly ResearcherSummary $summary,
        private readonly array $affiliationPatterns = [],
        private readonly ?float $autoApproveAt = null,
        private readonly ?float $setAsideBelow = null,
    ) {}

    public function forResearcher(EntryContract $researcher): ScoringReport
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
        $approved = 0;
        $setAside = 0;

        foreach ($candidates as $entry) {
            $outcome = $this->score($entry, $fingerprint, $prevalence);
            $approved += $outcome === 'approved' ? 1 : 0;
            $setAside += $outcome === 'set_aside' ? 1 : 0;
            $scored++;
        }

        // Scoring is the last thing that happens after every fetch, decision,
        // import and calibration, so the researcher's own page is brought up
        // to date here, once, from the final state.
        $this->summary->refresh(Entry::find($researcher->id()) ?? $researcher);

        return new ScoringReport($scored, $approved, $setAside);
    }

    /**
     * @return string `approved`, `set_aside` or `scored`
     */
    private function score(EntryContract $entry, Fingerprint $fingerprint, ?int $prevalence): string
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

        $values = [
            'confidence_score' => $score,
            'confidence_state' => $state,
            'confidence_breakdown' => $breakdown,
            'scored_at' => \date(DATE_ATOM),
        ];

        $outcome = 'scored';

        if ($this->approves($score, $state)) {
            // Approved by the score, and the record says so: no verifier,
            // `auto_approved` set, the breakdown kept as the reason. A person
            // can still reject it, and that rejection is durable.
            $values += [
                'verification_status' => 'approved',
                'verified_by' => null,
                'verified_at' => \date(DATE_ATOM),
                'auto_approved' => true,
                'deferred_at' => null,
                'discarded_at' => null,
            ];
            $outcome = 'approved';
        } elseif ($this->setsAside($score, $state)) {
            // Below the floor: still a candidate, still in the collection,
            // still confirmable, but out of the digest and (by filter) the
            // queue, so a common name does not bury a verifier. Never
            // deleted: a discard that can be seen can be reversed.
            if ($entry->get('discarded_at') === null) {
                $values['discarded_at'] = \date(DATE_ATOM);
            }

            $outcome = 'set_aside';
        } elseif ($entry->get('discarded_at') !== null) {
            // Risen back above the floor, usually because the fingerprint
            // grew. It is asked about again.
            $values['discarded_at'] = null;
        }

        $entry->merge($values);
        $entry->saveQuietly();

        return $outcome;
    }

    /**
     * The floor, like the threshold, applies only to a number that means
     * something. Certain never sets aside; the prior never does either.
     */
    private function setsAside(float $score, string $state): bool
    {
        if ($this->setAsideBelow === null) {
            return false;
        }

        return $state === 'calibrated' && $score < $this->setAsideBelow;
    }

    /**
     * The threshold applies to a number that means something: a certain
     * anchor (1.0 by definition) or a calibrated probability. It never
     * applies to the prior, whose 0.95 is an ordering, not nineteen in
     * twenty.
     */
    private function approves(float $score, string $state): bool
    {
        if ($this->autoApproveAt === null) {
            return false;
        }

        return \in_array($state, ['certain', 'calibrated'], true) && $score >= $this->autoApproveAt;
    }
}
