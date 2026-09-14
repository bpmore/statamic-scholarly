<?php

declare(strict_types=1);

use Bpmore\Scholarly\Scoring\Calibrator;
use Bpmore\Scholarly\Scoring\Example;
use Bpmore\Scholarly\Scoring\Features;
use Bpmore\Scholarly\Scoring\LogisticModel;
use Bpmore\Scholarly\Scoring\Reliability;

/**
 * A synthetic site where the truth differs from the prior in one known way:
 * affiliation is worth much more than the prior says, and co-authors much
 * less. Deterministic, so the fit is repeatable.
 *
 * @return list<Example>
 */
function syntheticDecisions(int $n, int $seed = 7): array
{
    \mt_srand($seed);
    $truth = new LogisticModel([
        'intercept' => -2.0, 'name_full' => 1.0, 'name_surname' => 0.2, 'affiliation_match' => 4.5,
        'coauthor_overlap' => 0.5, 'shared_coauthors' => 0.2, 'journal_seen' => 0.5, 'corroboration' => 1.0, 'name_prevalence' => -0.6,
    ], 'truth');

    $examples = [];

    for ($i = 0; $i < $n; $i++) {
        $vector = [
            'intercept' => 1.0,
            'name_full' => \mt_rand(0, 3) > 0 ? 1.0 : 0.0,
            'name_surname' => 0.0,
            'affiliation_match' => \mt_rand(0, 1) ? 1.0 : 0.0,
            'coauthor_overlap' => \mt_rand(0, 100) / 100,
            'shared_coauthors' => \mt_rand(0, 4) / 4,
            'journal_seen' => \mt_rand(0, 1) ? 1.0 : 0.0,
            'corroboration' => (float) \mt_rand(0, 1),
            'name_prevalence' => \mt_rand(0, 30) / 10,
        ];
        $vector['name_surname'] = $vector['name_full'] === 1.0 ? 0.0 : 1.0;

        $examples[] = new Example($vector, \mt_rand(0, 10000) / 10000 < $truth->probability($vector));
    }

    return $examples;
}

it('is exactly the prior with no decisions', function (): void {
    $fitted = (new Calibrator)->fit([], LogisticModel::prior());

    expect($fitted->weights())->toBe(LogisticModel::prior()->weights())
        ->and($fitted->state())->toBe('calibrated');
});

it('moves toward what the decisions show and does better than the prior on decisions it did not see', function (): void {
    $examples = syntheticDecisions(400);
    $prior = LogisticModel::prior();
    $calibrator = new Calibrator;

    $fitted = $calibrator->fit($examples, $prior);
    $weights = $fitted->weights();

    // Affiliation: prior 2.0, truth 4.5. Co-authors: prior 4.0, truth 0.5.
    expect($weights['affiliation_match'])->toBeGreaterThan(2.5)
        ->and($weights['coauthor_overlap'])->toBeLessThan(3.0);

    $priorReliability = Reliability::ofModel($examples, $prior);
    $fittedReliability = Reliability::crossValidated($examples, $prior, $calibrator);

    expect($fittedReliability->brier)->toBeLessThan($priorReliability->brier)
        ->and($fittedReliability->ece)->toBeLessThan($priorReliability->ece)
        ->and($fittedReliability->ece)->toBeLessThan(0.1, 'a fitted 0.90 should observe within ten points of nine in ten')
        ->and($fittedReliability->examples)->toBe(400);
});

it('barely moves on a handful of decisions, because the prior holds it', function (): void {
    $few = \array_slice(syntheticDecisions(400), 0, 6);

    $fitted = (new Calibrator)->fit($few, LogisticModel::prior());

    foreach (LogisticModel::prior()->weights() as $term => $prior) {
        expect(\abs($fitted->weights()[$term] - $prior))->toBeLessThan(1.5, $term);
    }
});

it('measures reliability in ten bins and knows a coin from a model', function (): void {
    $sure = [];

    for ($i = 0; $i < 100; $i++) {
        // A model that says 0.95 and is right 95 times in 100.
        $sure[] = [0.95, $i < 95];
    }

    $reflect = new ReflectionMethod(Reliability::class, 'fromPredictions');
    $reliability = $reflect->invoke(null, $sure);

    expect($reliability->ece)->toBeLessThan(0.01)
        ->and($reliability->bins[9]['count'])->toBe(100)
        ->and($reliability->bins[9]['observed'])->toBe(0.95);

    $coin = [];

    for ($i = 0; $i < 100; $i++) {
        $coin[] = [0.95, $i < 50];
    }

    $coinReliability = $reflect->invoke(null, $coin);

    expect($coinReliability->ece)->toBeGreaterThan(0.4)
        ->and($coinReliability->brier)->toBeGreaterThan(0.4);
});
