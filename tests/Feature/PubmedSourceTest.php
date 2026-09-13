<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Ncbi\PubmedSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/*
 * As with the ORCID tests, the handle is deliberately NOT the shipped default
 * (`pubmed_author_id`). A source reading the default by name passes here and
 * fails on every other site as a researcher with no publications.
 */
const AUTHOR_HANDLE = 'host_pubmed_query';

const ESEARCH = 'eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi*';
const ESUMMARY_URL = 'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*';

function pubmedSource(): PubmedSource
{
    config()->set('statamic-scholarly.researchers.fields', ['pubmed_author' => AUTHOR_HANDLE]);

    return app(PubmedSource::class);
}

function pubmedResearcher(array $data): \Statamic\Contracts\Entries\Entry
{
    return Entry::make()->collection('faculty')->data($data);
}

function ncbiFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../__fixtures__/ncbi/'.$name);
}

/**
 * The recorded search page says count=63 with five ids; the recorded summary
 * batch has those five plus an unknown PMID. This trims the search to say the
 * five are the whole answer, so the two fixtures agree.
 */
function esearchComplete(): string
{
    $decoded = json_decode(ncbiFixture('esearch-carberry-j-page-1.json'), true);
    $decoded['esearchresult']['count'] = '5';

    return json_encode($decoded);
}

beforeEach(function (): void {
    Collection::make('faculty')->save();
    config()->set('statamic-scholarly.ncbi.email', 'ops@example.edu');
    config()->set('statamic-scholarly.sources.pubmed.enabled', true);
    Cache::flush();
});

it('is configured only when enabled and the host has mapped an author field', function (): void {
    expect(pubmedSource()->isConfigured())->toBeTrue();

    config()->set('statamic-scholarly.sources.pubmed.enabled', false);
    expect(app(PubmedSource::class)->isConfigured())->toBeFalse();

    config()->set('statamic-scholarly.sources.pubmed.enabled', true);
    config()->set('statamic-scholarly.researchers.fields', []);
    expect(app(PubmedSource::class)->isConfigured())->toBeFalse();
});

it('searches by the host-mapped author string, then summarises every PMID found', function (): void {
    Http::fake([
        ESEARCH => Http::response(esearchComplete(), 200),
        ESUMMARY_URL => Http::response(ncbiFixture('esummary-carberry-j-page-1.json'), 200),
    ]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    expect($outcome->isComplete())->toBeTrue()
        ->and($outcome->sourcesRun)->toBe(['pubmed'])
        ->and($outcome->candidates)->toHaveCount(5)
        ->and($outcome->candidates[0]->pmid)->toBe('42392556')
        ->and($outcome->candidates[4]->doi)->toBe('10.1161/CIRCULATIONAHA.125.074645');

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'esearch.fcgi')
        && $r['term'] === '"Carberry J"[Author]'
        && $r['retmax'] === 500
        && $r['tool'] === 'statamic-scholarly'
        && $r['email'] === 'ops@example.edu');
    Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'esummary.fcgi')
        && $r['id'] === '42392556,41640490,41516974,40842283,40788977');
});

it('ignores the shipped default handle when the host mapped a different one', function (): void {
    Http::fake();

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher(['pubmed_author_id' => 'Carberry J']));

    Http::assertNothingSent();

    expect($outcome->isEmptyBecauseNothingRan())->toBeTrue()
        ->and($outcome->failures[0]->reason)->toBe(Reason::NotConfigured)
        ->and(str_contains($outcome->failures[0]->detail, AUTHOR_HANDLE))->toBeTrue('the failure names the field to fill in');
});

it('pages through a search larger than one page before summarising', function (): void {
    $page1 = json_decode(ncbiFixture('esearch-carberry-j-page-1.json'), true);
    $page1['esearchresult']['count'] = '7';
    $page2 = $page1;
    $page2['esearchresult']['retstart'] = '5';
    $page2['esearchresult']['idlist'] = ['40788977', '1'];

    Http::fake([
        ESEARCH => Http::sequence()->push($page1, 200)->push($page2, 200),
        ESUMMARY_URL => static function (Request $r) {
            $result = ['uids' => []];

            foreach (explode(',', $r['id']) as $id) {
                $result[$id] = ['uid' => $id, 'title' => 'T '.$id];
            }

            return Http::response(['result' => $result], 200);
        },
    ]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    // Six unique PMIDs: 40788977 appears on both pages, as it does when a
    // record is modified between two page requests.
    expect($outcome->isComplete())->toBeTrue()
        ->and($outcome->candidates)->toHaveCount(6);

    Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'esearch.fcgi') && $r['retstart'] === 5);
});

it('reports zero hits as a complete empty answer, not as a failure', function (): void {
    Http::fake([ESEARCH => Http::response(ncbiFixture('esearch-no-items.json'), 200)]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Zzyzxq Qq']));

    expect($outcome->candidates)->toBe([])
        ->and($outcome->isComplete())->toBeTrue()
        ->and($outcome->sourcesRun)->toBe(['pubmed']);

    Http::assertSentCount(1);
});

it('refuses to run without a contact email, loudly, and sends nothing', function (): void {
    config()->set('statamic-scholarly.ncbi.email', null);
    Http::fake();

    $source = pubmedSource();
    $outcome = $source->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    Http::assertNothingSent();

    expect($source->isConfigured())->toBeTrue('a missing email is a refusal, not a quiet skip')
        ->and($outcome->isEmptyBecauseNothingRan())->toBeTrue()
        ->and($outcome->failures[0]->reason)->toBe(Reason::RefusedLocally)
        ->and(str_contains($outcome->failures[0]->detail, 'SCHOLARLY_NCBI_EMAIL'))->toBeTrue();
});

it('refuses an author string it would not send', function (): void {
    Http::fake();

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'carberry[au] OR 12345']));

    Http::assertNothingSent();

    expect($outcome->failures[0]->reason)->toBe(Reason::RefusedLocally);
});

it('passes since through as a modification-date window', function (): void {
    Http::fake([ESEARCH => Http::response(ncbiFixture('esearch-no-items.json'), 200)]);

    pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']), '2024-02-29');

    Http::assertSent(static fn (Request $r): bool => $r['datetype'] === 'mdat' && $r['mindate'] === '2024/02/29' && $r['maxdate'] === '3000');
});

it('reports an outage at either endpoint as a transport failure', function (string $failing): void {
    Http::fake([
        ESEARCH => $failing === 'esearch' ? Http::response('', 503) : Http::response(esearchComplete(), 200),
        ESUMMARY_URL => Http::response('', 503),
    ]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    expect($outcome->candidates)->toBe([])
        ->and($outcome->isEmptyBecauseNothingRan())->toBeTrue()
        ->and($outcome->failures[0]->reason)->toBe(Reason::TransportFailed)
        ->and(str_contains($outcome->failures[0]->detail, $failing))->toBeTrue('the failure names the endpoint');
})->with(['esearch', 'esummary']);

it('reports an unreachable NCBI as a transport failure rather than throwing', function (): void {
    Http::fake([ESEARCH => static fn () => throw new ConnectionException('cURL error 28: timed out')]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    expect($outcome->failures[0]->reason)->toBe(Reason::TransportFailed);
});

it('reports a search body it cannot read as an unexpected response', function (): void {
    Http::fake([ESEARCH => Http::response('{"esearchresult":{"ERROR":"Invalid db name specified: pubmedd"}}', 200)]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    expect($outcome->failures[0]->reason)->toBe(Reason::UnexpectedResponse)
        ->and(str_contains($outcome->failures[0]->detail, 'Invalid db name'))->toBeTrue();
});

it('reports a PMID the search returned but the summary disowned as partial, keeping the rest', function (): void {
    $search = json_decode(ncbiFixture('esearch-carberry-j-page-1.json'), true);
    $search['esearchresult']['count'] = '6';
    $search['esearchresult']['idlist'][] = '99999999';

    Http::fake([
        ESEARCH => Http::response($search, 200),
        ESUMMARY_URL => Http::response(ncbiFixture('esummary-carberry-j-page-1.json'), 200),
    ]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    expect($outcome->candidates)->toHaveCount(5)
        ->and($outcome->isComplete())->toBeFalse()
        ->and($outcome->sourcesRun)->toBe([])
        ->and($outcome->failures[0]->reason)->toBe(Reason::UnexpectedResponse)
        ->and(str_contains($outcome->failures[0]->detail, '99999999'))->toBeTrue();
});

it('marks every candidate as an estimate from pubmed, never as a claim', function (): void {
    Http::fake([
        ESEARCH => Http::response(esearchComplete(), 200),
        ESUMMARY_URL => Http::response(ncbiFixture('esummary-carberry-j-page-1.json'), 200),
    ]);

    $outcome = pubmedSource()->fetchForResearcher(pubmedResearcher([AUTHOR_HANDLE => 'Carberry J']));

    foreach ($outcome->candidates as $candidate) {
        expect($candidate->sourceKey)->toBe('pubmed');
    }

    expect($outcome->candidates)->not->toBe([]);
});
