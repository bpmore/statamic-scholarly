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
