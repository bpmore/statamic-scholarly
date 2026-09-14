<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * Whether a model's numbers mean what they say, measured on decisions it
 * did not see.
 *
 * Brier score: mean squared distance between the probability and the
 * outcome; lower is better, 0.25 is a coin. Expected calibration error:
 * across bins of predicted probability, how far the predicted average is
 * from the observed rate, weighted by bin size; this is the number behind
 * "0.90 means roughly nine in ten". Both are computed by k-fold cross
 * validation so the fit is never scored on its own training data.
 */
final readonly class Reliability
{
    /**
     * @param  array<int, array{lower: float, upper: float, count: int, predicted: float, observed: float}>  $bins
     */
    public function __construct(
        public float $brier,
        public float $ece,
        public array $bins,
        public int $examples,
    ) {}

    /**
     * @param  list<Example>  $examples
     */
    public static function crossValidated(array $examples, LogisticModel $prior, Calibrator $calibrator, int $folds = 5): self
    {
        $n = \count($examples);
        $folds = \max(2, \min($folds, $n));
        $predictions = [];

        for ($k = 0; $k < $folds; $k++) {
            $train = [];
            $test = [];

            foreach ($examples as $i => $example) {
                if ($i % $folds === $k) {
                    $test[] = $example;
                } else {
                    $train[] = $example;
                }
            }

            $model = $calibrator->fit($train, $prior);

            foreach ($test as $example) {
                $predictions[] = [$model->probability($example->vector), $example->positive];
            }
        }

        return self::fromPredictions($predictions);
    }

    /**
     * The same measures for a fixed model, no fitting: how the prior does.
     *
     * @param  list<Example>  $examples
     */
    public static function ofModel(array $examples, LogisticModel $model): self
    {
        $predictions = [];

        foreach ($examples as $example) {
            $predictions[] = [$model->probability($example->vector), $example->positive];
        }

        return self::fromPredictions($predictions);
    }

    /**
     * @param  list<array{0: float, 1: bool}>  $predictions
     */
    private static function fromPredictions(array $predictions): self
    {
        $n = \count($predictions);

        if ($n === 0) {
            return new self(0.0, 0.0, [], 0);
        }

        $brier = 0.0;
        $binCount = 10;
        $bins = [];

        for ($b = 0; $b < $binCount; $b++) {
            $bins[$b] = ['lower' => $b / $binCount, 'upper' => ($b + 1) / $binCount, 'count' => 0, 'predicted' => 0.0, 'observed' => 0.0];
        }

        foreach ($predictions as [$p, $positive]) {
            $y = $positive ? 1.0 : 0.0;
            $brier += ($p - $y) ** 2;
            $b = \min($binCount - 1, (int) \floor($p * $binCount));
            $bins[$b]['count']++;
            $bins[$b]['predicted'] += $p;
            $bins[$b]['observed'] += $y;
        }

        $ece = 0.0;

        foreach ($bins as $b => $bin) {
            if ($bin['count'] === 0) {
                continue;
            }

            $bins[$b]['predicted'] = \round($bin['predicted'] / $bin['count'], 4);
            $bins[$b]['observed'] = \round($bin['observed'] / $bin['count'], 4);
            $ece += ($bin['count'] / $n) * \abs($bins[$b]['predicted'] - $bins[$b]['observed']);
        }

        return new self(\round($brier / $n, 4), \round($ece, 4), \array_values($bins), $n);
    }
}
