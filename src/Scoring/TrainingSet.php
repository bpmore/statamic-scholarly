<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Every decision on the site as a labelled example, pooled across
 * researchers.
 *
 * Positives: approved entries, and candidates anchored by ORCID (the free
 * labels the spec counts on). Negatives: rejected entries. Each example's
 * signals are computed against a fingerprint that does NOT include the
 * paper itself: a paper's own co-authors are in the fingerprint precisely
 * because it is certain, and measuring it against them would teach the
 * model that certain papers have perfect co-author overlap.
 *
 * Pooled, not per researcher: most people never make enough decisions to
 * fit anything, and the prevalence term carries the per-person adjustment.
 */
final class TrainingSet
{
    /**
     * @param  list<string>  $affiliationPatterns
     */
    public function __construct(
        private readonly PublicationsCollection $collection,
        private readonly ResearcherFieldMap $fields,
        private readonly string $researcherCollection,
        private readonly array $affiliationPatterns = [],
    ) {}

    /**
     * @return array{examples: list<Example>, positives: int, negatives: int, researchers: int}
     */
    public function build(): array
    {
        $entries = Entry::query()
            ->where('collection', $this->collection->handle())
            ->whereIn('verification_status', ['approved', 'rejected', 'candidate'])
            ->get();

        $examples = [];
        $positives = 0;
        $negatives = 0;
        $researchersWithLabels = 0;

        foreach (Entry::query()->where('collection', $this->researcherCollection)->get() as $researcher) {
            $id = (string) $researcher->id();
            $values = $researcher->data()->all();
            $orcid = $this->fields->read('orcid', $values);
            $names = \array_values(\array_filter([$researcher->get('title'), $this->fields->read('pubmed_author', $values)], 'is_string'));
            $prevalence = $researcher->get(ScoreCandidates::PREVALENCE_FIELD);
            $prevalence = \is_int($prevalence) || (\is_string($prevalence) && \ctype_digit($prevalence)) ? (int) $prevalence : null;

            $mine = $entries->filter(fn (EntryContract $e): bool => \in_array($id, \array_map('strval', (array) $e->get('researchers', [])), true))->values();
            $certain = $mine->filter(fn (EntryContract $e): bool => FingerprintBuilder::isCertain($e, $orcid))->values();
            $labelled = 0;

            foreach ($mine as $entry) {
                $status = $entry->get('verification_status');
                $isCertain = FingerprintBuilder::isCertain($entry, $orcid);

                if ($status === 'rejected') {
                    $positive = false;
                } elseif ($isCertain) {
                    $positive = true;
                } else {
                    continue;
                }

                // Leave-one-out: the fingerprint this paper is measured
                // against is built from the OTHER certain papers.
                $fingerprint = Fingerprint::start($names, $orcid);

                foreach ($certain as $other) {
                    if ((string) $other->id() === (string) $entry->id()) {
                        continue;
                    }

                    $fingerprint = $fingerprint->withPaper(FingerprintBuilder::authorsOf($other), \is_string($other->get('journal')) ? $other->get('journal') : null);
                }

                $sources = \array_values(\array_filter((array) $entry->get('source_apis', []), 'is_string'));

                // Scored as if unanchored: the model learns from what the
                // signals look like on a true paper, not from the anchor.
                $candidate = new CandidatePublication(
                    sourceKey: 'pubmed',
                    title: \is_string($entry->get('title')) ? $entry->get('title') : null,
                    journal: \is_string($entry->get('journal')) ? $entry->get('journal') : null,
                    authors: \array_map(static fn (array $a): array => [...$a, 'orcid' => null], FingerprintBuilder::authorsOf($entry)),
                );

                $signals = Signals::compute($candidate, $fingerprint, $this->affiliationPatterns, $prevalence, \count($sources));

                if ($signals->nameMatch === NameMatch::None) {
                    // The model never scores these (they are 0 by rule), so
                    // it must not learn from them either.
                    continue;
                }

                $examples[] = new Example(Features::vector($signals), $positive);
                $positive ? $positives++ : $negatives++;
                $labelled++;
            }

            if ($labelled > 0) {
                $researchersWithLabels++;
            }
        }

        return ['examples' => $examples, 'positives' => $positives, 'negatives' => $negatives, 'researchers' => $researchersWithLabels];
    }
}
