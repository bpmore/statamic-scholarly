<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * A weight per term over Features::vector(), through a sigmoid.
 *
 * Two instances exist: the shipped prior (`uncalibrated`) and one fitted to
 * this site's decisions (`calibrated`). Same terms, same arithmetic; only
 * the weights and the claim differ. Framework free.
 */
final readonly class LogisticModel implements Model
{
    /**
     * @param  array<string, float>  $weights  One per Features::TERMS
     * @param  array<string, mixed>  $meta  How it was made, for the report
     */
    public function __construct(
        private array $weights,
        private string $state,
        public array $meta = [],
    ) {
        foreach (Features::TERMS as $term) {
            if (! \array_key_exists($term, $weights)) {
                throw new \InvalidArgumentException('Missing weight for term "'.$term.'".');
            }
        }
    }

    /**
     * The hand-set starting weights. Their shape follows the spec (co-author
     * overlap outranks affiliation; a common name lowers everything); their
     * numbers are a starting point for calibration, not a claim.
     */
    public static function prior(): self
    {
        return new self([
            'intercept' => -3.0,
            'name_full' => 2.0,
            'name_surname' => 0.5,
            'affiliation_match' => 2.0,
            'coauthor_overlap' => 4.0,
            'shared_coauthors' => 2.0,
            'journal_seen' => 0.8,
            'corroboration' => 1.0,
            'name_prevalence' => -0.7,
        ], 'uncalibrated');
    }

    public function state(): string
    {
        return $this->state;
    }

    /**
     * @return array<string, float>
     */
    public function weights(): array
    {
        return $this->weights;
    }

    public function contributions(Signals $signals): array
    {
        $out = [];

        foreach (Features::vector($signals) as $term => $value) {
            $out[$term] = $this->weights[$term] * $value;
        }

        return $out;
    }

    public function score(Signals $signals): float
    {
        if ($signals->nameMatch === NameMatch::None) {
            // No author line matches the researcher at all. Whatever else
            // agrees, this is not evidence the paper is theirs.
            return 0.0;
        }

        return \round(self::sigmoid(\array_sum($this->contributions($signals))), 4);
    }

    /**
     * @param  array<string, float>  $vector
     */
    public function probability(array $vector): float
    {
        $logit = 0.0;

        foreach ($vector as $term => $value) {
            $logit += ($this->weights[$term] ?? 0.0) * $value;
        }

        return self::sigmoid($logit);
    }

    public static function sigmoid(float $logit): float
    {
        return 1 / (1 + \exp(-$logit));
    }
}
