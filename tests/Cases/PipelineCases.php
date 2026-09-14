<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Fetching\FetchOutcome;
use Bpmore\Scholarly\Fetching\PublicationWriter;
use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Fetching\SourceFailure;
use Bpmore\Scholarly\Fetching\SourceRegistry;
use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\Deduplicator;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Tests\Support\FakeSource;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/*
 * Neither handle is the shipped default (`people`, `publications`). The
 * pipeline creates the owned collection under whatever config says, and
 * links to whatever researcher collection config says.
 */
const RESEARCHERS = 'faculty';
const PUBLICATIONS = 'research_outputs';


function aResearcher(string $title = 'Josiah Carberry'): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection(RESEARCHERS)->slug(\Illuminate\Support\Str::slug($title))->data(['title' => $title]);
    $entry->save();

    return $entry;
}

function pipeline(FakeSource ...$sources): FetchForResearcher
{
    $collection = app(PublicationsCollection::class);

    return new FetchForResearcher(new SourceRegistry(...$sources), new Deduplicator, new PublicationWriter($collection), $collection);
}

function found(string $key, CandidatePublication ...$candidates): FetchOutcome
{
    return (new FetchOutcome)->withCandidates(...$candidates)->withSourceRun($key);
}

function publicationsFor(\Statamic\Contracts\Entries\Entry $researcher): array
{
    return Entry::query()->where('collection', PUBLICATIONS)->get()
        ->filter(fn ($e) => in_array((string) $researcher->id(), array_map('strval', (array) $e->get('researchers')), true))
        ->values()->all();
}

/**
 * The fetch pipeline, run against the Stache and against the Eloquent driver alike.
 *
 * @return array<string, Closure(): void>
 */
function pipelineCasesSetup(): void
{
    config()->set('statamic-scholarly.researchers.collection', RESEARCHERS);
    config()->set('statamic-scholarly.publications.collection', PUBLICATIONS);
    Collection::make(RESEARCHERS)->save();
}

function pipelineCases(): array
{
    return [
        'creates the owned collection and blueprint under the configured handles, once' => function (): void {
        expect(Collection::findByHandle(PUBLICATIONS))->toBeNull();

        pipeline(new FakeSource('orcid', found('orcid')))(aResearcher());
        pipeline(new FakeSource('orcid', found('orcid')))(aResearcher('Second'));

        $blueprint = Blueprint::find('collections.'.PUBLICATIONS.'.publication');

        expect(Collection::findByHandle(PUBLICATIONS))->not->toBeNull()
            ->and($blueprint)->not->toBeNull()
            ->and($blueprint->hasField('verification_status'))->toBeTrue()
            ->and($blueprint->field('researchers')->config()['collections'])->toBe([RESEARCHERS])
            ->and(Collection::findByHandle('publications'))->toBeNull('the default handle is not created');
        },

        'writes one candidate entry per merged paper, linked to the researcher, never as approved' => function (): void {
        $researcher = aResearcher();
        $orcid = new FakeSource('orcid', found('orcid',
            new CandidatePublication('orcid', title: 'Paper A', doi: '10.1000/a', publicationDate: '2020'),
        ));
        $pubmed = new FakeSource('pubmed', found('pubmed',
            new CandidatePublication('pubmed', title: 'Paper A.', doi: 'https://doi.org/10.1000/A', pmid: '11', journal: 'J', authors: [['name' => 'Carberry J']]),
            new CandidatePublication('pubmed', title: 'Paper B', pmid: '22'),
        ));

        $report = pipeline($orcid, $pubmed)($researcher);

        $entries = publicationsFor($researcher);

        expect($report->isComplete())->toBeTrue()
            ->and($report->written->created)->toBe(2)
            ->and($entries)->toHaveCount(2);

        $a = collect($entries)->first(fn ($e) => $e->get('doi') === '10.1000/a');
        $b = collect($entries)->first(fn ($e) => $e->get('pmid') === '22');

        expect($a->get('title'))->toBe('Paper A')
            ->and($a->get('pmid'))->toBe('11')
            ->and($a->get('journal'))->toBe('J')
            ->and($a->get('source_apis'))->toBe(['orcid', 'pubmed'])
            ->and($a->get('authors'))->toBe([['name' => 'Carberry J', 'affiliation' => null, 'orcid' => null]])
            ->and($a->get('verification_status'))->toBe('candidate')
            ->and($a->get('researchers'))->toBe([(string) $researcher->id()])
            ->and($b->get('verification_status'))->toBe('candidate')
            ->and($b->get('source_apis'))->toBe(['pubmed']);

        foreach ($entries as $entry) {
            expect($entry->get('verification_status'))->not->toBe('approved');
        }
        },

        'leaves a rejected entry alone when the source returns the paper again' => function (): void {
        // The spec's durability rule: a namesake paper returned nightly must not
        // re-queue, or the verifier's rejection meant nothing.
        $researcher = aResearcher();
        $source = new FakeSource('pubmed', found('pubmed', new CandidatePublication('pubmed', title: 'Namesake paper', pmid: '33')));
        $run = pipeline($source);

        $run($researcher);
        $entry = publicationsFor($researcher)[0];
        $entry->set('verification_status', 'rejected')->set('title', 'Rejected by a person')->save();

        $source->willReturn(found('pubmed', new CandidatePublication('pubmed', title: 'Namesake paper, refreshed title', pmid: '33')));
        $report = $run($researcher);

        $entries = publicationsFor($researcher);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->get('verification_status'))->toBe('rejected')
            ->and($entries[0]->get('title'))->toBe('Rejected by a person')
            ->and($report->written->suppressed)->toBe(1)
            ->and($report->written->created)->toBe(0);
        },

        'keeps an approved entry as the verifier edited it, adding only identifiers and sources' => function (): void {
        $researcher = aResearcher();
        $source = new FakeSource('orcid', found('orcid', new CandidatePublication('orcid', title: 'Typo in titel', doi: '10.1000/a')));
        $run = pipeline($source);

        $run($researcher);
        $entry = publicationsFor($researcher)[0];
        $entry->set('verification_status', 'approved')->set('title', 'Corrected title')->save();

        $source->willReturn(found('orcid', new CandidatePublication('orcid', title: 'Typo in titel', doi: '10.1000/a', pmid: '44')));
        $pubmed = new FakeSource('pubmed', found('pubmed', new CandidatePublication('pubmed', title: 'Typo in titel.', doi: '10.1000/a')));
        $report = pipeline($source, $pubmed)($researcher);

        $entries = publicationsFor($researcher);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->get('title'))->toBe('Corrected title')
            ->and($entries[0]->get('verification_status'))->toBe('approved')
            ->and($entries[0]->get('pmid'))->toBe('44')
            ->and($entries[0]->get('source_apis'))->toBe(['orcid', 'pubmed'])
            ->and($report->written->keptApproved)->toBe(1);
        },

        'refreshes a candidate nobody has touched' => function (): void {
        $researcher = aResearcher();
        $source = new FakeSource('pubmed', found('pubmed', new CandidatePublication('pubmed', title: 'Ahead of print', pmid: '55')));
        $run = pipeline($source);

        $run($researcher);
        $source->willReturn(found('pubmed', new CandidatePublication('pubmed', title: 'Ahead of print', pmid: '55', journal: 'Now in an issue', publicationDate: '2026-01')));
        $report = $run($researcher);

        $entries = publicationsFor($researcher);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->get('journal'))->toBe('Now in an issue')
            ->and($report->written->refreshed)->toBe(1);
        },

        'stamps last-fetched per source, only for sources that completed, and passes it back as since' => function (): void {
        $researcher = aResearcher();
        $orcid = new FakeSource('orcid', found('orcid'));
        $pubmed = new FakeSource('pubmed', (new FetchOutcome)->withFailure(new SourceFailure('pubmed', Reason::TransportFailed, 'HTTP 503')));

        $report = pipeline($orcid, $pubmed)($researcher);

        $stamps = Entry::find($researcher->id())->get(FetchForResearcher::LAST_FETCHED_FIELD);

        expect($report->isComplete())->toBeFalse()
            ->and($orcid->calls[0]['since'])->toBeNull()
            ->and($pubmed->calls[0]['since'])->toBeNull()
            ->and(array_keys($stamps))->toBe(['orcid'])
            ->and(strtotime($stamps['orcid']))->not->toBeFalse();

        $researcher = Entry::find($researcher->id());
        pipeline($orcid, $pubmed)($researcher);

        expect($orcid->calls[1]['since'])->toBe($stamps['orcid'])
            ->and($pubmed->calls[1]['since'])->toBeNull('a source that failed is asked for everything again');
        },

        'lets an explicit since override the stamp for every source' => function (): void {
        $researcher = aResearcher();
        $researcher->set(FetchForResearcher::LAST_FETCHED_FIELD, ['orcid' => '2020-01-01T00:00:00+00:00'])->save();
        $orcid = new FakeSource('orcid', found('orcid'));

        pipeline($orcid)($researcher, null, '2024-06-01');

        expect($orcid->calls[0]['since'])->toBe('2024-06-01');
        },

        'reports an unconfigured source as skipped and the run as partial, without stamping it' => function (): void {
        $researcher = aResearcher();
        $orcid = new FakeSource('orcid', found('orcid'));
        $scopus = new FakeSource('scopus', found('scopus'), configured: false);

        $report = pipeline($orcid, $scopus)($researcher);

        expect($report->skipped)->toBe(['scopus'])
            ->and($report->isComplete())->toBeFalse()
            ->and($scopus->calls)->toBe([])
            ->and(array_keys(Entry::find($researcher->id())->get(FetchForResearcher::LAST_FETCHED_FIELD)))->toBe(['orcid']);
        },

        'runs only the selected source and refuses an unknown one' => function (): void {
        $researcher = aResearcher();
        $orcid = new FakeSource('orcid', found('orcid'));
        $pubmed = new FakeSource('pubmed', found('pubmed'));

        pipeline($orcid, $pubmed)($researcher, 'pubmed');

        expect($orcid->calls)->toBe([])
            ->and($pubmed->calls)->toHaveCount(1);

        expect(static fn () => pipeline($orcid, $pubmed)($researcher, 'orcd'))->toThrow(InvalidArgumentException::class);
        },

        'links title matches both ways for review and merges nothing' => function (): void {
        $researcher = aResearcher();
        $source = new FakeSource('pubmed', found('pubmed',
            new CandidatePublication('pubmed', title: 'Same title', pmid: '1'),
            new CandidatePublication('pubmed', title: 'Same title', pmid: '2'),
        ));

        $report = pipeline($source)($researcher);

        $entries = publicationsFor($researcher);
        $byPmid = collect($entries)->keyBy(fn ($e) => $e->get('pmid'));

        expect($entries)->toHaveCount(2)
            ->and($report->titleMatches)->toBe(2)
            ->and($byPmid['1']->get('possible_duplicates'))->toBe([(string) $byPmid['2']->id()])
            ->and($byPmid['2']->get('possible_duplicates'))->toBe([(string) $byPmid['1']->id()]);
        },

        'attaches a co-author to the one entry for a shared paper while it is a candidate' => function (): void {
        $a = aResearcher('A');
        $b = aResearcher('B');
        $paper = new CandidatePublication('orcid', title: 'Joint paper', doi: '10.1000/joint');

        pipeline(new FakeSource('orcid', found('orcid', $paper)))($a);
        $report = pipeline(new FakeSource('orcid', found('orcid', $paper)))($b);

        $all = Entry::query()->where('collection', PUBLICATIONS)->get();

        expect($all)->toHaveCount(1)
            ->and($all[0]->get('researchers'))->toBe([(string) $a->id(), (string) $b->id()])
            ->and($all[0]->get('verification_status'))->toBe('candidate')
            ->and($report->written->refreshed)->toBe(1);
        },

        'sends an approved paper back to review when a fetch attaches a researcher nobody confirmed' => function (): void {
        // A PubMed name match could attach a namesake to an approved paper, and
        // approval is for every name listed. The entry keeps its snapshot and
        // its history, loses its approved status, and gains the new name.
        $a = aResearcher('A');
        $b = aResearcher('B');
        $paper = new CandidatePublication('pubmed', title: 'Approved for A', pmid: '77');

        pipeline(new FakeSource('pubmed', found('pubmed', $paper)))($a);
        $entry = publicationsFor($a)[0];
        $entry->merge(['verification_status' => 'approved', 'title' => 'Edited by A verifier', 'verified_by' => 'user-1'])->save();

        $report = pipeline(new FakeSource('pubmed', found('pubmed', $paper)))($b);

        $entry = Entry::find($entry->id());

        expect($entry->get('researchers'))->toBe([(string) $a->id(), (string) $b->id()])
            ->and($entry->get('verification_status'))->toBe('candidate')
            ->and($entry->get('title'))->toBe('Edited by A verifier')
            ->and($entry->get('verified_by'))->toBe('user-1')
            ->and($report->written->reopened)->toBe(1)
            ->and($report->written->keptApproved)->toBe(0);
        },

        'does not attach a researcher to a rejected paper' => function (): void {
        $a = aResearcher('A');
        $b = aResearcher('B');
        $paper = new CandidatePublication('pubmed', title: 'Rejected for A', pmid: '88');

        pipeline(new FakeSource('pubmed', found('pubmed', $paper)))($a);
        $entry = publicationsFor($a)[0];
        $entry->set('verification_status', 'rejected')->save();

        $report = pipeline(new FakeSource('pubmed', found('pubmed', $paper)))($b);

        expect(Entry::find($entry->id())->get('researchers'))->toBe([(string) $a->id()])
            ->and($report->written->suppressed)->toBe(1)
            ->and(Entry::query()->where('collection', PUBLICATIONS)->count())->toBe(1);
        }
    ];
}
