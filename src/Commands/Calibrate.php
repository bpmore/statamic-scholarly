<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Commands;

use Bpmore\Scholarly\Scoring\Calibrator;
use Bpmore\Scholarly\Scoring\LogisticModel;
use Bpmore\Scholarly\Scoring\ModelStore;
use Bpmore\Scholarly\Scoring\Reliability;
use Bpmore\Scholarly\Scoring\ScoreCandidates;
use Bpmore\Scholarly\Scoring\TrainingSet;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Entry;

/**
 * `scholarly:calibrate`: fit the confidence model to this site's decisions,
 * say how well it does on decisions it did not see, and rescore.
 *
 * Refuses to call the result calibrated below the configured number of
 * decisions. A model fitted to eight decisions is a prior with opinions,
 * and the state field would be lying.
 */
final class Calibrate extends Command
{
    use RunsInPlease;

    protected $signature = 'scholarly:calibrate
        {--dry-run : Report without saving the model or rescoring}
        {--forget : Delete the fitted model and go back to the prior}';

    protected $description = 'Fit the confidence model to this site\'s confirm and reject decisions';

    public function handle(TrainingSet $trainingSet, Calibrator $calibrator, ModelStore $store): int
    {
        if ($this->option('forget')) {
            $store->forget();
            $this->rescoreAll();
            $this->info('Fitted model removed. Every candidate is scored by the prior again, state "uncalibrated".');
            $this->line('Entries approved on the removed model stay approved and still say "Approved on score"; filter on that to review them.');

            return self::SUCCESS;
        }

        $set = $trainingSet->build();
        $examples = $set['examples'];
        $minLabels = (int) config('statamic-scholarly.scoring.min_labels', 30);
        $minPerClass = (int) config('statamic-scholarly.scoring.min_labels_per_class', 10);

        $this->line(\sprintf('Decisions: %d positive, %d negative, from %d researcher(s).', $set['positives'], $set['negatives'], $set['researchers']));

        if (\count($examples) < $minLabels || $set['positives'] < $minPerClass || $set['negatives'] < $minPerClass) {
            $this->warn(\sprintf(
                'Not enough to calibrate: need %d decisions with at least %d of each kind. Candidates keep the prior, state "uncalibrated".',
                $minLabels,
                $minPerClass,
            ));

            return self::FAILURE;
        }

        $prior = LogisticModel::prior();
        $priorReliability = Reliability::ofModel($examples, $prior);
        $fittedReliability = Reliability::crossValidated($examples, $prior, $calibrator);

        $this->line(\sprintf('Prior on these decisions:      Brier %.4f, calibration error %.4f', $priorReliability->brier, $priorReliability->ece));
        $this->line(\sprintf('Fitted, cross-validated:       Brier %.4f, calibration error %.4f', $fittedReliability->brier, $fittedReliability->ece));

        foreach ($fittedReliability->bins as $bin) {
            if ($bin['count'] > 0) {
                $this->line(\sprintf('  predicted %.2f to %.2f: %3d decision(s), predicted %.2f, observed %.2f', $bin['lower'], $bin['upper'], $bin['count'], $bin['predicted'], $bin['observed']));
            }
        }

        if ($fittedReliability->brier > $priorReliability->brier) {
            $this->warn('The fit does worse than the prior on decisions it did not see. Not saved; the prior stays.');

            return self::FAILURE;
        }

        $model = $calibrator->fit($examples, $prior, [
            'fitted_at' => \date(DATE_ATOM),
            'positives' => $set['positives'],
            'negatives' => $set['negatives'],
            'researchers' => $set['researchers'],
            'brier' => $fittedReliability->brier,
            'calibration_error' => $fittedReliability->ece,
            'prior_brier' => $priorReliability->brier,
        ]);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        $store->save($model);
        $this->rescoreAll();
        $this->info(\sprintf('Model saved. Candidates rescored, state "calibrated". A score of 0.90 was right about %s of the time in the nearest bin.', self::nearest($fittedReliability, 0.9)));

        return self::SUCCESS;
    }

    /**
     * Resolved fresh, AFTER the model file changed: a scorer made earlier
     * holds the model it was made with.
     */
    private function rescoreAll(): void
    {
        $scorer = app(ScoreCandidates::class);
        $collection = (string) config('statamic-scholarly.researchers.collection');
        $approved = 0;
        $setAside = 0;

        foreach (Entry::query()->where('collection', $collection)->get() as $researcher) {
            $report = $scorer->forResearcher($researcher);
            $approved += $report->autoApproved;
            $setAside += $report->setAside;
        }

        if ($approved > 0) {
            $this->warn(\sprintf('%d candidate(s) approved on score and now public. Each says "Approved on score" and can be rejected.', $approved));
        }

        if ($setAside > 0) {
            $this->line(\sprintf('%d candidate(s) set aside below the floor: kept, not mailed, filter "Set aside on score" to see them.', $setAside));
        }
    }

    private static function nearest(Reliability $r, float $p): string
    {
        foreach ($r->bins as $bin) {
            if ($p >= $bin['lower'] && $p < $bin['upper'] && $bin['count'] > 0) {
                return \sprintf('%.0f%%', $bin['observed'] * 100);
            }
        }

        return 'no decisions in that bin';
    }
}
