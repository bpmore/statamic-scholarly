<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * The initial weights: a logistic model over the signals, chosen by hand.
 *
 * This is a PRIOR, not a calibrated model, and every score it produces is
 * labelled as such. Its shape follows the spec's expectations (co-author
 * overlap outranks affiliation; a common name lowers everything) but the
 * numbers are a starting point for calibration, not a claim about
 * probability. A site with enough labelled decisions replaces it.
 *
 * Framework free. Anchored candidates never reach it: they are certain.
 */
final readonly class PriorModel implements Model
{
    public function __construct(
        private float $intercept = -3.0,
        private float $nameFull = 2.0,
        private float $nameSurname = 0.5,
        private float $affiliation = 2.0,
        private float $coauthorOverlap = 4.0,
        private float $sharedCoauthorBonus = 0.5,
        private float $journal = 0.8,
        private float $corroboration = 1.0,
        private float $prevalencePerDecade = -0.7,
    ) {}

    public function state(): string
    {
        return 'uncalibrated';
    }

    /**
     * @return array<string, float> Each term's contribution to the logit, plus the total
     */
    public function contributions(Signals $s): array
    {
        $terms = [
            'intercept' => $this->intercept,
            'name_match' => match ($s->nameMatch) {
                NameMatch::Full => $this->nameFull,
                NameMatch::Surname => $this->nameSurname,
                NameMatch::None => 0.0,
            },
            'affiliation_match' => $s->affiliationMatch ? $this->affiliation : 0.0,
            'coauthor_overlap' => $this->coauthorOverlap * $s->coauthorOverlap
                + $this->sharedCoauthorBonus * \min($s->sharedCoauthors, 4),
            'journal_seen' => $s->journalSeen ? $this->journal : 0.0,
            'corroboration' => $this->corroboration * ($s->corroboration - 1),
            // Every tenfold in how many PubMed records the name matches
            // costs the same again. A name matching ten records is a
            // near-identifier; one matching ten thousand is a lottery.
            'name_prevalence' => $s->namePrevalence === null || $s->namePrevalence < 10
                ? 0.0
                : $this->prevalencePerDecade * \log10($s->namePrevalence / 10),
        ];

        return $terms;
    }

    public function score(Signals $s): float
    {
        if ($s->nameMatch === NameMatch::None) {
            // No author line matches the researcher at all. Whatever else
            // agrees, this is not evidence the paper is theirs.
            return 0.0;
        }

        $logit = \array_sum($this->contributions($s));

        return \round(1 / (1 + \exp(-$logit)), 4);
    }
}
