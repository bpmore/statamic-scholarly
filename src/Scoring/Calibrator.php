<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * Fits the weights to this site's decisions, pulled toward the prior.
 *
 * Logistic regression by gradient descent, with an L2 penalty on the
 * DISTANCE FROM THE PRIOR rather than from zero. With no decisions the fit
 * is the prior; with a few it moves a little; with many the decisions win.
 * That is what lets a site with thirty decisions have a model at all
 * without the thirty decisions being over-read.
 *
 * Framework free, so the fit can be pinned on synthetic data.
 */
final readonly class Calibrator
{
    /**
     * @param  float  $lambda  Pull toward the prior; larger trusts the decisions less
     * @param  float  $learningRate  Gradient step
     * @param  int  $iterations  Steps; enough that the fit settles on a few hundred examples
     */
    public function __construct(
        private float $lambda = 1.0,
        private float $learningRate = 0.1,
        private int $iterations = 2000,
    ) {}

    /**
     * @param  list<Example>  $examples
     * @param  array<string, mixed>  $meta
     */
    public function fit(array $examples, LogisticModel $prior, array $meta = []): LogisticModel
    {
        $priorWeights = $prior->weights();
        $weights = $priorWeights;
        $n = \count($examples);

        if ($n === 0) {
            return new LogisticModel($priorWeights, 'calibrated', $meta);
        }

        for ($i = 0; $i < $this->iterations; $i++) {
            $gradient = \array_fill_keys(Features::TERMS, 0.0);

            foreach ($examples as $example) {
                $error = $this->probability($weights, $example->vector) - ($example->positive ? 1.0 : 0.0);

                foreach ($example->vector as $term => $value) {
                    $gradient[$term] += $error * $value;
                }
            }

            foreach (Features::TERMS as $term) {
                $gradient[$term] = $gradient[$term] / $n + $this->lambda * ($weights[$term] - $priorWeights[$term]) / $n;
                $weights[$term] -= $this->learningRate * $gradient[$term];
            }
        }

        foreach ($weights as $term => $w) {
            $weights[$term] = \round($w, 4);
        }

        return new LogisticModel($weights, 'calibrated', $meta);
    }

    /**
     * @param  array<string, float>  $weights
     * @param  array<string, float>  $vector
     */
    private function probability(array $weights, array $vector): float
    {
        $logit = 0.0;

        foreach ($vector as $term => $value) {
            $logit += $weights[$term] * $value;
        }

        return LogisticModel::sigmoid($logit);
    }
}
