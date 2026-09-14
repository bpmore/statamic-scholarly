<?php

declare(strict_types=1);

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Bpmore\Scholarly\Ncbi\EsummaryFetcher;
use Bpmore\Scholarly\Ncbi\NcbiUnavailable;
use Bpmore\Scholarly\Ncbi\UnexpectedNcbiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const ESUMMARY = 'eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi*';

function esummaryBody(): string
{
    return (string) file_get_contents(__DIR__.'/../__fixtures__/ncbi/esummary-carberry-j-page-1.json');
}

beforeEach(function (): void {
    config()->set('statamic-scholarly.ncbi.email', 'ops@example.edu');
    Cache::flush();
});

it('is the default binding for the metadata seam', function (): void {
    expect(app(PublicationMetadataFetcher::class))->toBeInstanceOf(EsummaryFetcher::class);
});

it('fetches a batch in one identified request and keys the answer by PMID, in the order asked', function (): void {
    Http::fake([ESUMMARY => Http::response(esummaryBody(), 200)]);

    $rows = app(PublicationMetadataFetcher::class)->summariesForPmids(['40788977', '42392556', '99999999']);

    // PHP turns numeric-string keys into ints; compare as strings.
    expect(array_map('strval', array_keys($rows)))->toBe(['40788977', '42392556', '99999999'])
        ->and($rows['40788977']['title'])->toStartWith('Intramyocardial Hemorrhage')
        ->and($rows['99999999'])->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $r): bool => $r['id'] === '40788977,42392556,99999999'
        && $r['tool'] === 'statamic-scholarly'
        && $r['email'] === 'ops@example.edu');
});

it('serves a hit and a recorded miss from cache without a second request', function (): void {
    Http::fake([ESUMMARY => Http::response(esummaryBody(), 200)]);
    $fetcher = app(PublicationMetadataFetcher::class);

    $fetcher->summariesForPmids(['40788977', '99999999']);
    $again = $fetcher->summariesForPmids(['40788977', '99999999']);

    Http::assertSentCount(1);

    expect($again['40788977']['uid'])->toBe('40788977')
        ->and($again['99999999'])->toBeNull();
});

it('asks only for the PMIDs the cache does not have', function (): void {
    Http::fake([ESUMMARY => Http::response(esummaryBody(), 200)]);
    $fetcher = app(PublicationMetadataFetcher::class);

    $fetcher->summariesForPmids(['40788977']);
    $fetcher->summariesForPmids(['40788977', '41640490']);

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => $r['id'] === '41640490');
});

it('does NOT cache a timeout as a missing record', function (): void {
    // The failure this guards: a rate-limit block during a nightly run was
    // once recorded as "no such paper" for a week.
    Http::fakeSequence(ESUMMARY)
        ->pushResponse(static fn () => throw new ConnectionException('cURL error 28: timed out'))
        ->push(esummaryBody(), 200);
    $fetcher = app(PublicationMetadataFetcher::class);

    expect(static fn () => $fetcher->summaryForPmid('40788977'))->toThrow(NcbiUnavailable::class);

    $row = $fetcher->summaryForPmid('40788977');

    // Had the timeout been cached as a miss, this would be null with no request.
    expect($row)->not->toBeNull()
        ->and($row['uid'])->toBe('40788977');
});

it('throws rather than answering null when NCBI refuses', function (int $status): void {
    Http::fake([ESUMMARY => Http::response('', $status)]);

    expect(static fn () => app(PublicationMetadataFetcher::class)->summaryForPmid('40788977'))
        ->toThrow(NcbiUnavailable::class);

    expect(Cache::get('scholarly_esummary_v1:40788977'))->toBeNull();
})->with([429, 500]);

it('throws on a body it cannot read instead of recording misses', function (): void {
    Http::fake([ESUMMARY => Http::response('<html>maintenance</html>', 200)]);

    expect(static fn () => app(PublicationMetadataFetcher::class)->summariesForPmids(['40788977']))
        ->toThrow(UnexpectedNcbiResponse::class);

    expect(Cache::get('scholarly_esummary_v1:40788977'))->toBeNull();
});

it('splits a large batch into requests of at most the batch size', function (): void {
    $pmids = array_map(static fn (int $i): string => (string) (1_000_000 + $i), range(1, 250));

    Http::fake([ESUMMARY => static function (Request $r): \GuzzleHttp\Promise\PromiseInterface {
        $ids = explode(',', $r['id']);
        $result = ['uids' => $ids];

        foreach ($ids as $id) {
            $result[$id] = ['uid' => $id, 'title' => 'T'];
        }

        return Http::response(['result' => $result], 200);
    }]);

    $rows = app(PublicationMetadataFetcher::class)->summariesForPmids($pmids);

    Http::assertSentCount(3);
    Http::assertSent(static fn (Request $r): bool => count(explode(',', $r['id'])) <= EsummaryFetcher::BATCH_SIZE);

    expect($rows)->toHaveCount(250)
        ->and(array_map('strval', array_keys($rows)))->toBe($pmids);
});

it('refuses to send at all without a contact email', function (): void {
    config()->set('statamic-scholarly.ncbi.email', null);
    Http::fake();

    expect(static fn () => app(PublicationMetadataFetcher::class)->summaryForPmid('40788977'))
        ->toThrow(\Bpmore\Scholarly\Ncbi\MissingContactEmail::class);

    Http::assertNothingSent();
});

const EFETCH = 'eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*';

function efetchBody(): string
{
    return (string) file_get_contents(__DIR__.'/../__fixtures__/ncbi/efetch-carberry-j-two-articles.xml');
}

it('fetches details as XML in one identified request, and caches hits and reported misses', function (): void {
    Http::fake([EFETCH => Http::response(efetchBody(), 200)]);
    $fetcher = app(PublicationMetadataFetcher::class);

    $details = $fetcher->detailsForPmids(['40788977', '99999999']);
    $again = $fetcher->detailsForPmids(['40788977', '99999999']);

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $r): bool => $r['retmode'] === 'xml' && $r['id'] === '40788977,99999999' && $r['tool'] === 'statamic-scholarly');

    expect($details['40788977']['authors'][1]['name'])->toBe('Carberry J')
        ->and($details['99999999'])->toBeNull()
        ->and($again)->toBe($details);
});

it('does NOT cache an efetch outage as a missing record', function (): void {
    Http::fakeSequence(EFETCH)->push('', 503)->push(efetchBody(), 200);
    $fetcher = app(PublicationMetadataFetcher::class);

    expect(static fn () => $fetcher->detailsForPmids(['40788977']))->toThrow(NcbiUnavailable::class);
    expect(Cache::get('scholarly_efetch_v1:40788977'))->toBeNull();

    expect($fetcher->detailsForPmids(['40788977'])['40788977'])->not->toBeNull();
});
