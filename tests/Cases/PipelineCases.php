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

    return new FetchForResearcher(new SourceRegistry(...$sources), new Deduplicator, new PublicationWriter($collection), $collection, app(\Bpmore\Scholarly\Scoring\ScoreCandidates::class));
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
    // These cases are about writing. Scoring runs after every write, and
    // with auto-approval on an ORCID-sourced candidate would be approved
    // before the assertions look at it; that behaviour has its own cases.
    config()->set('statamic-scholarly.scoring.auto_approve', false);
    config()->set('statamic-scholarly.researchers.collection', RESEARCHERS);
    config()->set('statamic-scholarly.publications.collection', PUBLICATIONS);
    Collection::make(RESEARCHERS)->save();
}

function pipelineCases(): array
{
    return [
        'updates the same ORCID records on repeat without merging equal titles' => function (): void {
            $case = dirname(__DIR__, 2).'/corpus/cases/orcid-record-identity/';
            $input = json_decode(file_get_contents($case.'input.json'), true, flags: JSON_THROW_ON_ERROR);
            $expected = json_decode(file_get_contents($case.'expected.json'), true, flags: JSON_THROW_ON_ERROR);
            $candidates = array_map(fn (array $row) => new CandidatePublication(...$row), $input['candidates']);
            $groups = (new Deduplicator)->merge($candidates);
            expect($groups)->toHaveCount($expected['groups']);
            $researcher = aResearcher();
            $fetch = pipeline(new FakeSource('orcid', found('orcid', ...$candidates)));
            expect($fetch($researcher)->written->created)->toBe($expected['firstCreated']);
            $ids = array_map(fn ($e) => (string) $e->id(), publicationsFor($researcher));
            expect($fetch($researcher)->written->created)->toBe($expected['repeatCreated'])
                ->and(publicationsFor($researcher))->toHaveCount($expected['savedRecords'])
                ->and(array_map(fn ($e) => (string) $e->id(), publicationsFor($researcher)))->toBe($ids);
        },

        'keeps source aliases across DOI refreshes and later keyless fetches' => function (): void {
            $person = aResearcher();
            $id = 'https://pub.orcid.org/v3.0/person/work/1';
            $alias = 'https://pub.orcid.org/v3.0/person/work/2';
            $run = fn (CandidatePublication $c) => pipeline(new FakeSource('orcid', found('orcid', $c)))($person);
            $run(new CandidatePublication('orcid', title: 'Original', doi: '10.1000/a'));
            $entry = publicationsFor($person)[0];
            $entry->merge(['verification_status' => 'approved', 'title' => 'Human correction'])->save();
            $run(new CandidatePublication('orcid', title: 'Source title', doi: '10.1000/a', sourceRecordIds: [$id, $alias]));
            $report = $run(new CandidatePublication('orcid', title: 'Changed title', sourceRecordIds: [$alias]));
            $saved = Entry::find($entry->id());
            expect($report->written->created)->toBe(0)
                ->and($saved->get('title'))->toBe('Human correction')
                ->and($saved->get('source_record_ids'))->toBe([$id, $alias])
                ->and($saved->get('verification_status'))->toBe('approved');
        },

        'keeps a source record identity when candidates gain a DOI and refresh without it' => function (): void {
            $person = aResearcher();
            $run = fn (CandidatePublication $c) => pipeline(new FakeSource('orcid', found('orcid', $c)))($person);
            $run(new CandidatePublication('orcid', title: 'First', sourceRecordIds: ['orcid:person:1', 'orcid:person:2']));
            $run(new CandidatePublication('orcid', title: 'Second', doi: '10.1000/a', sourceRecordIds: ['orcid:person:2']));
            $report = $run(new CandidatePublication('orcid', title: 'Third', sourceRecordIds: ['orcid:person:1']));
            $entries = publicationsFor($person);
            expect($report->written->created)->toBe(0)
                ->and($entries)->toHaveCount(1)
                ->and($entries[0]->get('title'))->toBe('Third')
                ->and($entries[0]->get('doi'))->toBe('10.1000/a')
                ->and($entries[0]->get('source_record_ids'))->toBe(['orcid:person:1', 'orcid:person:2']);
        },

        'does not recreate a rejected source record or overwrite its review' => function (): void {
            $person = aResearcher();
            $candidate = new CandidatePublication('orcid', title: 'Rejected work', sourceRecordIds: ['orcid:person:1']);
            $fetch = pipeline(new FakeSource('orcid', found('orcid', $candidate)));
            $fetch($person);
            $entry = publicationsFor($person)[0];
            $entry->merge(['verification_status' => 'rejected', 'rejection_reason' => 'Wrong person'])->save();
            $report = $fetch($person);
            expect($report->written->created)->toBe(0)
                ->and($report->written->suppressed)->toBe(1)
                ->and(publicationsFor($person))->toHaveCount(1)
                ->and(Entry::find($entry->id())->get('rejection_reason'))->toBe('Wrong person');
        },

        'does not guess an identity for legacy records with the same title' => function (): void {
            $person = aResearcher();
            pipeline(new FakeSource('orcid', found('orcid', new CandidatePublication('orcid', title: 'Same title'))))($person);
            $fetch = pipeline(new FakeSource('orcid', found('orcid', new CandidatePublication('orcid', title: 'Same title', sourceRecordIds: ['orcid:person:1']))));
            expect($fetch($person)->written->created)->toBe(1)
                ->and($fetch($person)->written->created)->toBe(0)
                ->and(publicationsFor($person))->toHaveCount(2);
        },

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
        $pubmed = new FakeSource('pubmed', found('pubmed', new CandidatePublication('pubmed', title: 'Typo in titel.', doi: '10.1000/a', journal: 'Filled in')));
        $report = pipeline($source, $pubmed)($researcher);

        $entries = publicationsFor($researcher);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->get('title'))->toBe('Corrected title')
            ->and($entries[0]->get('verification_status'))->toBe('approved')
            ->and($entries[0]->get('pmid'))->toBe('44')
            ->and($entries[0]->get('journal'))->toBe('Filled in', 'a blank the entry never had is filled; the title it had is not')
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

        'keeps a stored value when tonight\'s source has none for it' => function (): void {
            // Found by the shared-identifier case: a refresh once wrote every
            // snapshot field, nulls included, and a PMID-only source erased
            // the DOI a DOI-only source had stored the night before.
            $researcher = aResearcher();
            pipeline(new FakeSource('orcid', found('orcid', new CandidatePublication('orcid', title: 'Both ids', doi: '10.1000/keep', pmid: '9', journal: 'J'))))($researcher);
            pipeline(new FakeSource('pubmed', found('pubmed', new CandidatePublication('pubmed', title: 'Both ids', pmid: '9'))))($researcher);

            $entries = publicationsFor($researcher);

            expect($entries)->toHaveCount(1)
                ->and($entries[0]->get('doi'))->toBe('10.1000/keep')
                ->and($entries[0]->get('journal'))->toBe('J')
                ->and($entries[0]->get('source_apis'))->toBe(['orcid', 'pubmed'], 'a source that reported it last month still reported it');
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

        'links a title match against an entry from an earlier run, both ways, and reports it' => function (): void {
            // Last month's conference abstract and tonight's full paper never
            // arrive in the same run, so a within-run check would miss them.
            $researcher = aResearcher();
            $run = pipeline(new FakeSource('pubmed', found('pubmed',
                new CandidatePublication('pubmed', title: 'Deep Learning for ECG Analysis', pmid: '1'),
            )));
            $run($researcher);

            $later = pipeline(new FakeSource('orcid', found('orcid',
                new CandidatePublication('orcid', title: 'Deep learning for ECG analysis.', doi: '10.1000/full'),
            )));
            $report = $later($researcher);

            $entries = collect(publicationsFor($researcher))->keyBy(fn ($e) => $e->get('title_key'));
            $abstract = collect(publicationsFor($researcher))->first(fn ($e) => $e->get('pmid') === '1');
            $full = collect(publicationsFor($researcher))->first(fn ($e) => $e->get('doi') === '10.1000/full');

            expect(publicationsFor($researcher))->toHaveCount(2)
                ->and($report->titleMatches)->toBe(1)
                ->and($full->get('possible_duplicates'))->toBe([(string) $abstract->id()])
                ->and(Entry::find($abstract->id())->get('possible_duplicates'))->toBe([(string) $full->id()]);
        },

        'does not title-link two entries that already share an identifier' => function (): void {
            // Two entries with one DOI, kept apart by hand: a title link
            // between them would only nag. The fetch matches the second by
            // PMID, refreshes it, and must leave the first unlinked.
            $researcher = aResearcher();
            app(PublicationsCollection::class)->ensure();
            $first = Entry::make()->collection(PUBLICATIONS)->slug('kept-apart-a')->data([
                'title' => 'Same title', 'title_key' => 'same title', 'doi' => '10.1000/same',
                'verification_status' => 'rejected', 'researchers' => [(string) $researcher->id()],
            ]);
            $first->save();
            $second = Entry::make()->collection(PUBLICATIONS)->slug('kept-apart-b')->data([
                'title' => 'Same title', 'title_key' => 'same title', 'doi' => '10.1000/same', 'pmid' => '5',
                'verification_status' => 'candidate', 'researchers' => [(string) $researcher->id()],
            ]);
            $second->save();

            $report = pipeline(new FakeSource('pubmed', found('pubmed', new CandidatePublication('pubmed', title: 'Same title', pmid: '5'))))($researcher);

            expect($report->written->refreshed)->toBe(1)
                ->and($report->titleMatches)->toBe(0)
                ->and(Entry::find($first->id())->get('possible_duplicates'))->toBeNull()
                ->and(Entry::find($second->id())->get('possible_duplicates'))->toBeNull();
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

/**
 * @return array<string, Closure(): void>
 */
function upgradeCases(): array
{
    return [
        'adds fields a later version shipped to a blueprint that already exists, and touches nothing else' => function (): void {
            $collection = app(PublicationsCollection::class);
            $collection->ensure();

            // A blueprint from an older version: the site renamed a display,
            // and two fields this version ships do not exist yet.
            $blueprint = Blueprint::find('collections.'.PUBLICATIONS.'.publication');
            $contents = $blueprint->contents();
            $contents['tabs']['main']['sections'][0]['fields'][0]['field']['display'] = 'Paper title';
            $contents['tabs']['sidebar']['sections'][0]['fields'] = array_values(array_filter(
                $contents['tabs']['sidebar']['sections'][0]['fields'],
                fn (array $f) => ! in_array($f['handle'], ['discarded_at', 'auto_approved'], true),
            ));
            $blueprint->setContents($contents)->save();

            expect(Blueprint::find('collections.'.PUBLICATIONS.'.publication')->hasField('discarded_at'))->toBeFalse();

            $collection->ensure();

            $upgraded = Blueprint::find('collections.'.PUBLICATIONS.'.publication');

            expect($upgraded->hasField('discarded_at'))->toBeTrue()
                ->and($upgraded->hasField('auto_approved'))->toBeTrue()
                ->and($upgraded->field('title')->display())->toBe('Paper title', 'the host\'s change survives')
                ->and(count($upgraded->fields()->all()))->toBe(count($collection->addMissingFields($upgraded)) + count($upgraded->fields()->all()), 'a second pass adds nothing');
        },
    ];
}
