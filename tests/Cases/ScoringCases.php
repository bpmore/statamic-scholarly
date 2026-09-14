<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Fetching\FetchOutcome;
use Bpmore\Scholarly\Fetching\SourceRegistry;
use Bpmore\Scholarly\Ncbi\PubmedSource;
use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Scoring\ScoreCandidates;
use Bpmore\Scholarly\Tests\Support\FakeSource;
use Bpmore\Scholarly\Verification\Verifier;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

const SCORE_PUBS = 'research_outputs';
const SELF_ORCID = '0000-0002-4184-0714';

function scoringResearcher(): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection('faculty')->slug('jaclyn-carberry')->data([
        'title' => 'Jaclyn Carberry',
        'orcid' => SELF_ORCID,
        'ncbi_author_query' => 'Carberry J',
    ]);
    $entry->save();

    return $entry;
}

function scoredFetch(FakeSource ...$sources): FetchForResearcher
{
    app()->instance(SourceRegistry::class, new SourceRegistry(...$sources));

    return app(FetchForResearcher::class);
}

function scoredEntries(\Statamic\Contracts\Entries\Entry $researcher): \Illuminate\Support\Collection
{
    return Entry::query()->where('collection', SCORE_PUBS)->get()
        ->filter(fn ($e) => in_array((string) $researcher->id(), array_map('strval', (array) $e->get('researchers')), true))
        ->keyBy(fn ($e) => $e->get('title'));
}

/**
 * Scoring, run against the Stache and against the Eloquent driver alike.
 *
 * @return array<string, Closure(): void>
 */
function scoringCasesSetup(): void
{
    config()->set('statamic-scholarly.researchers.collection', 'faculty');
    config()->set('statamic-scholarly.researchers.fields', ['orcid' => 'orcid', 'pubmed_author' => 'ncbi_author_query']);
    config()->set('statamic-scholarly.publications.collection', SCORE_PUBS);
    config()->set('statamic-scholarly.affiliation_patterns', ['university of glasgow']);
    Collection::make('faculty')->save();
    app(PublicationsCollection::class)->ensure();
}

function scoringCases(): array
{
    return [
        'scores every candidate after a fetch, with a breakdown and an honest state' => function (): void {
            $researcher = scoringResearcher();

            $orcid = new FakeSource('orcid', (new FetchOutcome)->withCandidates(
                new CandidatePublication('orcid', title: 'Claimed', doi: '10.1000/claimed', journal: 'Circulation', authors: [
                    ['name' => 'Berry C'], ['name' => 'Carberry J'], ['name' => 'Lechner I'],
                ]),
            )->withSourceRun('orcid'));

            $pubmed = new FakeSource('pubmed', (new FetchOutcome)->withCandidates(
                new CandidatePublication('pubmed', title: 'With coauthors', pmid: '1', journal: 'Circulation', authors: [
                    ['name' => 'Berry C', 'affiliation' => 'Glasgow'], ['name' => 'Carberry J', 'affiliation' => 'University of Glasgow'], ['name' => 'Lechner I'],
                ]),
                new CandidatePublication('pubmed', title: 'Bare namesake', pmid: '2', journal: 'J Orthop', authors: [
                    ['name' => 'Carberry J', 'affiliation' => 'Somewhere Else'], ['name' => 'Nobody X'],
                ]),
                new CandidatePublication('pubmed', title: 'Own id on the line', pmid: '3', authors: [
                    ['name' => 'Carberry J', 'orcid' => SELF_ORCID, 'affiliation' => 'Elsewhere'],
                ]),
            )->withSourceRun('pubmed')->withNote(PubmedSource::NAME_PREVALENCE_NOTE, 63));

            scoredFetch($orcid, $pubmed)($researcher);

            $entries = scoredEntries($researcher);

            expect($entries)->toHaveCount(4)
                ->and(Entry::find($researcher->id())->get(ScoreCandidates::PREVALENCE_FIELD))->toBe(63);

            $claimed = $entries['Claimed'];
            $with = $entries['With coauthors'];
            $bare = $entries['Bare namesake'];
            $ownId = $entries['Own id on the line'];

            expect($claimed->get('confidence_state'))->toBe('certain')
                ->and((float) $claimed->get('confidence_score'))->toBe(1.0)
                ->and($ownId->get('confidence_state'))->toBe('certain')
                ->and($with->get('confidence_state'))->toBe('uncalibrated')
                ->and($bare->get('confidence_state'))->toBe('uncalibrated')
                ->and((float) $with->get('confidence_score'))->toBeGreaterThan((float) $bare->get('confidence_score'))
                ->and($with->get('confidence_breakdown')['signal_coauthor_overlap'])->toBe('1')
                ->and($with->get('confidence_breakdown')['signal_affiliation_match'])->toBe('yes')
                ->and($with->get('confidence_breakdown')['signal_name_prevalence'])->toBe('63')
                ->and($with->get('confidence_breakdown')['fingerprint_papers'])->toBe('2', 'the claimed paper and the own-id paper are certain')
                ->and($bare->get('confidence_breakdown')['signal_coauthor_overlap'])->toBe('0')
                ->and($bare->get('confidence_breakdown')['signal_affiliation_match'])->toBe('no')
                ->and(isset($with->get('confidence_breakdown')['weight_coauthor_overlap']))->toBeTrue('the breakdown shows each term');
        },

        'never scores an approved or rejected entry' => function (): void {
            $researcher = scoringResearcher();
            app(PublicationsCollection::class)->ensure();

            foreach (['approved', 'rejected'] as $status) {
                $entry = Entry::make()->collection(SCORE_PUBS)->slug('decided-'.$status)->data([
                    'title' => 'Decided '.$status, 'pmid' => $status === 'approved' ? '10' : '11',
                    'verification_status' => $status, 'researchers' => [(string) $researcher->id()],
                    'authors' => [['name' => 'Carberry J']],
                ]);
                $entry->save();
            }

            app(ScoreCandidates::class)->forResearcher($researcher);

            $entries = scoredEntries($researcher);

            expect($entries['Decided approved']->get('confidence_score'))->toBeNull()
                ->and($entries['Decided rejected']->get('confidence_score'))->toBeNull();
        },

        'confirming a paper enriches the fingerprint and rescores the rest' => function (): void {
            $researcher = scoringResearcher();
            $pubmed = new FakeSource('pubmed', (new FetchOutcome)->withCandidates(
                new CandidatePublication('pubmed', title: 'First', pmid: '1', authors: [['name' => 'Carberry J'], ['name' => 'Quinn JJ']]),
                new CandidatePublication('pubmed', title: 'Second', pmid: '2', authors: [['name' => 'Carberry J'], ['name' => 'Quinn JJ']]),
            )->withSourceRun('pubmed'));

            scoredFetch($pubmed)($researcher);

            $before = (float) scoredEntries($researcher)['Second']->get('confidence_score');
            $breakdownBefore = scoredEntries($researcher)['Second']->get('confidence_breakdown');

            $user = User::make()->id('v')->email('v@example.edu');
            $user->save();
            app(Verifier::class)->confirm(scoredEntries($researcher)['First'], $user);

            $after = scoredEntries($researcher)['Second'];

            expect($breakdownBefore['signal_shared_coauthors'])->toBe('0')
                ->and($after->get('confidence_breakdown')['signal_shared_coauthors'])->toBe('1', 'Quinn is now a known co-author')
                ->and((float) $after->get('confidence_score'))->toBeGreaterThan($before)
                ->and(scoredEntries($researcher)['First']->get('confidence_score'))->not->toBeNull('the score it had stays as history');
        },
    ];
}

/**
 * Calibration on entries, run against the Stache and the Eloquent driver.
 *
 * @return array<string, Closure(): void>
 */
function calibrationCases(): array
{
    return [
        'refuses to calibrate below the configured number of decisions, and says so' => function (): void {
            $researcher = scoringResearcher();
            app(PublicationsCollection::class)->ensure();
            decide($researcher, 'approved', 3);
            decide($researcher, 'rejected', 3);

            $this->artisan('scholarly:calibrate')
                ->expectsOutputToContain('Decisions: 3 positive, 3 negative, from 1 researcher(s).')
                ->expectsOutputToContain('Not enough to calibrate')
                ->assertFailed();

            expect(app(\Bpmore\Scholarly\Scoring\ModelStore::class)->load())->toBeNull()
                ->and(app(\Bpmore\Scholarly\Scoring\Model::class)->state())->toBe('uncalibrated');
        },

        'fits from pooled decisions, saves the model, and rescores every candidate as calibrated' => function (): void {
            config()->set('statamic-scholarly.scoring.min_labels', 20);
            config()->set('statamic-scholarly.scoring.min_labels_per_class', 8);
            app(PublicationsCollection::class)->ensure();

            // Two researchers pool. Their decisions agree with the prior's
            // shape, so the fit cannot do worse than the prior and is saved.
            $a = scoringResearcher();
            $b = Entry::make()->collection('faculty')->slug('b')->data(['title' => 'Bea Quinn', 'ncbi_author_query' => 'Quinn B']);
            $b->save();

            decide($a, 'approved', 8, ['Berry C', 'Lechner I']);
            decide($a, 'rejected', 6, ['Nobody X']);
            decide($b, 'approved', 4, ['Smith J']);
            decide($b, 'rejected', 4, ['Other Y']);

            $strong = Entry::make()->collection(SCORE_PUBS)->slug('strong-candidate')->data([
                'title' => 'Strong candidate', 'pmid' => '999', 'verification_status' => 'candidate',
                'researchers' => [(string) $a->id()], 'authors' => [['name' => 'Carberry J'], ['name' => 'Berry C'], ['name' => 'Lechner I']],
            ]);
            $strong->save();
            $weak = Entry::make()->collection(SCORE_PUBS)->slug('weak-candidate')->data([
                'title' => 'Weak candidate', 'pmid' => '998', 'verification_status' => 'candidate',
                'researchers' => [(string) $a->id()], 'authors' => [['name' => 'Carberry J'], ['name' => 'Stranger Z']],
            ]);
            $weak->save();

            // Before calibration the prior scores the strong one high, and
            // nothing is approved on it: the prior's 0.9 is not nine in ten.
            app(ScoreCandidates::class)->forResearcher($a);

            expect((float) Entry::find($strong->id())->get('confidence_score'))->toBeGreaterThan(0.9)
                ->and(Entry::find($strong->id())->get('verification_status'))->toBe('candidate');

            $this->artisan('scholarly:calibrate')
                ->expectsOutputToContain('Decisions: 12 positive, 10 negative, from 2 researcher(s).')
                ->expectsOutputToContain('Model saved.')
                ->expectsOutputToContain('1 candidate(s) approved on score and now public.')
                ->assertSuccessful();

            $model = app(\Bpmore\Scholarly\Scoring\ModelStore::class)->load();

            expect($model)->not->toBeNull()
                ->and($model->state())->toBe('calibrated')
                ->and($model->meta['positives'])->toBe(12)
                ->and(app(\Bpmore\Scholarly\Scoring\Model::class)->state())->toBe('calibrated');

            $approved = Entry::find($strong->id());
            $still = Entry::find($weak->id());

            expect($approved->get('verification_status'))->toBe('approved')
                ->and($approved->get('auto_approved'))->toBeTrue()
                ->and($approved->get('verified_by'))->toBeNull()
                ->and(strtotime((string) $approved->get('verified_at')))->not->toBeFalse()
                ->and($approved->get('confidence_state'))->toBe('calibrated')
                ->and((float) $approved->get('confidence_score'))->toBeGreaterThanOrEqual(0.9)
                ->and($still->get('verification_status'))->toBe('candidate')
                ->and($still->get('confidence_state'))->toBe('calibrated')
                ->and((float) $still->get('confidence_score'))->toBeLessThan(0.9);

            $this->artisan('scholarly:calibrate', ['--forget' => true])->assertSuccessful();

            expect(app(\Bpmore\Scholarly\Scoring\ModelStore::class)->load())->toBeNull()
                ->and(Entry::find($weak->id())->get('confidence_state'))->toBe('uncalibrated')
                ->and(Entry::find($strong->id())->get('verification_status'))->toBe('approved', 'forgetting the model does not undo a decision it made; the entry still says it was made on score');
        },

        'sets a weak candidate aside below the floor once calibrated, keeps it, and lifts it when it rises' => function (): void {
            config()->set('statamic-scholarly.scoring.min_labels', 20);
            config()->set('statamic-scholarly.scoring.min_labels_per_class', 8);
            config()->set('statamic-scholarly.scoring.discard_floor', 0.30);
            app(PublicationsCollection::class)->ensure();

            $a = scoringResearcher();
            decide($a, 'approved', 10, ['Berry C', 'Lechner I']);
            decide($a, 'rejected', 10, ['Nobody X']);

            $weak = Entry::make()->collection(SCORE_PUBS)->slug('weak')->data([
                'title' => 'Weak', 'pmid' => '998', 'verification_status' => 'candidate',
                'researchers' => [(string) $a->id()], 'authors' => [['name' => 'Carberry J'], ['name' => 'Stranger Z']],
            ]);
            $weak->save();

            // On the prior: scored low, but never set aside.
            app(ScoreCandidates::class)->forResearcher($a);

            expect((float) Entry::find($weak->id())->get('confidence_score'))->toBeLessThan(0.3)
                ->and(Entry::find($weak->id())->get('discarded_at'))->toBeNull();

            $this->artisan('scholarly:calibrate')
                ->expectsOutputToContain('1 candidate(s) set aside below the floor')
                ->assertSuccessful();

            $aside = Entry::find($weak->id());

            expect($aside->get('verification_status'))->toBe('candidate', 'set aside is not a status')
                ->and(strtotime((string) $aside->get('discarded_at')))->not->toBeFalse();

            // Not mailed while set aside.
            \Illuminate\Support\Facades\Notification::fake();
            $verifierUser = User::make()->id('vv')->email('vv@example.edu');
            $verifierUser->save();
            $a->set(\Bpmore\Scholarly\Notifications\NotifyVerifiers::VERIFIER_FIELD, 'vv')->save();

            expect(app(\Bpmore\Scholarly\Notifications\NotifyVerifiers::class)()->stamped)->toBe(0);

            // Its co-author becomes known: confirming another paper with
            // Stranger Z lifts it above the floor and it is asked about again.
            $lift = Entry::make()->collection(SCORE_PUBS)->slug('lift')->data([
                'title' => 'Lift', 'pmid' => '997', 'verification_status' => 'candidate',
                'researchers' => [(string) $a->id()], 'authors' => [['name' => 'Carberry J'], ['name' => 'Stranger Z'], ['name' => 'Berry C']],
            ]);
            $lift->save();
            app(Verifier::class)->confirm($lift, $verifierUser);

            expect(Entry::find($weak->id())->get('discarded_at'))->toBeNull()
                ->and((float) Entry::find($weak->id())->get('confidence_score'))->toBeGreaterThanOrEqual(0.3);

            $this->artisan('scholarly:calibrate', ['--forget' => true])->assertSuccessful();
        },

        'confirming a set-aside candidate clears the mark' => function (): void {
            $a = scoringResearcher();
            app(PublicationsCollection::class)->ensure();
            $entry = Entry::make()->collection(SCORE_PUBS)->slug('aside')->data([
                'title' => 'Aside', 'pmid' => '5', 'verification_status' => 'candidate',
                'researchers' => [(string) $a->id()], 'discarded_at' => '2026-01-01T00:00:00+00:00', 'authors' => [['name' => 'Carberry J']],
            ]);
            $entry->save();
            $user = User::make()->id('v2')->email('v2@example.edu');
            $user->save();

            app(Verifier::class)->confirm($entry, $user);

            expect(Entry::find($entry->id())->get('verification_status'))->toBe('approved')
                ->and(Entry::find($entry->id())->get('discarded_at'))->toBeNull();
        },

        'approves an ORCID-anchored candidate on its own, but never on the prior\'s number' => function (): void {
            $researcher = scoringResearcher();
            $pubmed = new FakeSource('pubmed', (new FetchOutcome)->withCandidates(
                new CandidatePublication('pubmed', title: 'Own id', pmid: '1', authors: [['name' => 'Carberry J', 'orcid' => SELF_ORCID]]),
                new CandidatePublication('pubmed', title: 'Merely likely', pmid: '2', authors: [['name' => 'Carberry J', 'affiliation' => 'University of Glasgow'], ['name' => 'Berry C']]),
            )->withSourceRun('pubmed'));
            $orcid = new FakeSource('orcid', (new FetchOutcome)->withCandidates(
                new CandidatePublication('orcid', title: 'Claimed', doi: '10.1000/claimed', authors: [['name' => 'Carberry J'], ['name' => 'Berry C']]),
            )->withSourceRun('orcid'));

            $report = scoredFetch($orcid, $pubmed)($researcher);
            $entries = scoredEntries($researcher);

            expect($entries['Own id']->get('verification_status'))->toBe('approved')
                ->and($entries['Own id']->get('auto_approved'))->toBeTrue()
                ->and($entries['Claimed']->get('verification_status'))->toBe('approved')
                ->and($entries['Merely likely']->get('verification_status'))->toBe('candidate')
                ->and($entries['Merely likely']->get('confidence_state'))->toBe('uncalibrated')
                ->and($report->autoApproved)->toBe(2)
                ->and(str_contains($report->summary(), '2 approved on score'))->toBeTrue();
        },

        'approves nothing when auto-approval is off, even an anchor' => function (): void {
            config()->set('statamic-scholarly.scoring.auto_approve', false);
            $researcher = scoringResearcher();
            $pubmed = new FakeSource('pubmed', (new FetchOutcome)->withCandidates(
                new CandidatePublication('pubmed', title: 'Own id', pmid: '1', authors: [['name' => 'Carberry J', 'orcid' => SELF_ORCID]]),
            )->withSourceRun('pubmed'));

            scoredFetch($pubmed)($researcher);

            expect(scoredEntries($researcher)['Own id']->get('verification_status'))->toBe('candidate')
                ->and(scoredEntries($researcher)['Own id']->get('confidence_state'))->toBe('certain');
        },

        'learns from a decided paper without letting it see its own co-authors' => function (): void {
            // Leave-one-out: the only approved paper's co-authors must not
            // count as overlap for that paper. If they did, every positive
            // would show full overlap and the model would learn that.
            $researcher = scoringResearcher();
            app(PublicationsCollection::class)->ensure();
            decide($researcher, 'approved', 1, ['Berry C']);

            $set = app(\Bpmore\Scholarly\Scoring\TrainingSet::class)->build();

            expect($set['positives'])->toBe(1)
                ->and($set['examples'][0]->vector['coauthor_overlap'])->toBe(0.0)
                ->and($set['examples'][0]->vector['name_full'])->toBe(1.0);
        },
    ];
}

/**
 * @param  list<string>  $coauthors
 */
function decide(\Statamic\Contracts\Entries\Entry $researcher, string $status, int $count, array $coauthors = ['Berry C']): void
{
    static $n = 0;

    for ($i = 0; $i < $count; $i++) {
        $n++;
        $authors = [['name' => $researcher->get('ncbi_author_query')]];

        foreach ($coauthors as $co) {
            $authors[] = ['name' => $co];
        }

        $entry = Entry::make()->collection(SCORE_PUBS)->slug('decided-'.$n)->data([
            'title' => 'Decided '.$n, 'pmid' => (string) (1000 + $n), 'verification_status' => $status,
            'researchers' => [(string) $researcher->id()], 'authors' => $authors, 'source_apis' => ['pubmed'],
        ]);
        $entry->save();
    }
}

/**
 * The researcher's own page, across the same drivers.
 *
 * @return array<string, Closure(): void>
 */
function summaryCases(): array
{
    return [
        'writes recent approved publications, the waiting count and the last fetch onto the researcher' => function (): void {
            $researcher = scoringResearcher();
            $pubmed = new FakeSource('pubmed', (new FetchOutcome)->withCandidates(
                new CandidatePublication('pubmed', title: 'Own id, new', pmid: '1', publicationDate: '2025', authors: [['name' => 'Carberry J', 'orcid' => SELF_ORCID]]),
                new CandidatePublication('pubmed', title: 'Own id, old', pmid: '2', publicationDate: '2019', authors: [['name' => 'Carberry J', 'orcid' => SELF_ORCID]]),
                new CandidatePublication('pubmed', title: 'Namesake', pmid: '3', authors: [['name' => 'Carberry J'], ['name' => 'Stranger Z']]),
                new CandidatePublication('pubmed', title: 'Another namesake', pmid: '4', authors: [['name' => 'Carberry J']]),
            )->withSourceRun('pubmed'));

            scoredFetch($pubmed)($researcher);

            $researcher = Entry::find($researcher->id());
            $entries = scoredEntries($researcher);

            expect($researcher->get(\Bpmore\Scholarly\Researchers\ResearcherSummary::RECENT_FIELD))->toBe([(string) $entries['Own id, new']->id(), (string) $entries['Own id, old']->id()], 'approved, newest first')
                ->and($researcher->get(\Bpmore\Scholarly\Researchers\ResearcherSummary::WAITING_FIELD))->toBe(2)
                ->and($researcher->get(\Bpmore\Scholarly\Researchers\ResearcherSummary::STATUS_FIELD))->toStartWith(date('Y-m-d'))
                ->and(str_contains((string) $researcher->get(\Bpmore\Scholarly\Researchers\ResearcherSummary::STATUS_FIELD), 'complete: 4 candidate(s) from pubmed'))->toBeTrue();

            // A decision moves the numbers.
            $user = User::make()->id('v3')->email('v3@example.edu');
            $user->save();
            app(Verifier::class)->reject($entries['Namesake'], $user);

            expect(Entry::find($researcher->id())->get(\Bpmore\Scholarly\Researchers\ResearcherSummary::WAITING_FIELD))->toBe(1);
        },

        'installs a fieldset pointing at the configured publications collection, once' => function (): void {
            $this->artisan('scholarly:install')->expectsOutputToContain('Fieldset "scholarly" written')->assertSuccessful();
            $this->artisan('scholarly:install')->expectsOutputToContain('already exists and was left alone')->assertSuccessful();

            $fieldset = \Statamic\Facades\Fieldset::find('scholarly');
            $recent = collect($fieldset->contents()['fields'])->firstWhere('handle', \Bpmore\Scholarly\Researchers\ResearcherSummary::RECENT_FIELD);

            expect($fieldset)->not->toBeNull()
                ->and($recent['field']['collections'])->toBe([SCORE_PUBS])
                ->and($recent['field']['read_only'])->toBeTrue()
                ->and(collect($fieldset->contents()['fields'])->pluck('handle')->all())->toBe(['scholarly_verifier', 'scholarly_recent_publications', 'scholarly_candidates_waiting', 'scholarly_status']);
        },
    ];
}
