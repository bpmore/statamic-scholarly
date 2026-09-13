<?php

declare(strict_types=1);

use Bpmore\Scholarly\Ncbi\EsearchResult;
use Bpmore\Scholarly\Ncbi\UnexpectedNcbiResponse;

/**
 * Recorded from NCBI on 2026-09-13 with retmax=5, so the page is smaller
 * than the count on purpose.
 *
 * @return array<string, mixed>
 */
function esearchPage(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../__fixtures__/ncbi/esearch-carberry-j-page-1.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('reads the ids and the total count, and knows a page is not the whole answer', function (): void {
    $page = EsearchResult::fromDecoded(esearchPage());

    expect($page->ids)->toBe(['42392556', '41640490', '41516974', '40842283', '40788977'])
        ->and($page->count)->toBe(63)
        ->and($page->retstart)->toBe(0)
        ->and($page->hasMore())->toBeTrue();
});

it('knows the last page when it sees it', function (): void {
    $decoded = esearchPage();
    $decoded['esearchresult']['retstart'] = '60';
    $decoded['esearchresult']['idlist'] = ['1', '2', '3'];

    $page = EsearchResult::fromDecoded($decoded);

    expect($page->retstart)->toBe(60)
        ->and($page->hasMore())->toBeFalse();
});

it('treats "No items found" as an empty page, not as an error', function (): void {
    $decoded = json_decode((string) file_get_contents(__DIR__.'/../__fixtures__/ncbi/esearch-no-items.json'), true, flags: JSON_THROW_ON_ERROR);

    $page = EsearchResult::fromDecoded($decoded);

    expect($page->ids)->toBe([])
        ->and($page->count)->toBe(0)
        ->and($page->hasMore())->toBeFalse();
});

it('refuses a body that is not an esearch result instead of reading it as empty', function (array $decoded): void {
    expect(static fn () => EsearchResult::fromDecoded($decoded))->toThrow(UnexpectedNcbiResponse::class);
})->with([
    'no esearchresult' => [['header' => ['type' => 'esearch']]],
    'an NCBI error with HTTP 200' => [['esearchresult' => ['ERROR' => 'Invalid db name specified: pubmedd']]],
    'idlist missing' => [['esearchresult' => ['count' => '3']]],
    'count missing' => [['esearchresult' => ['idlist' => ['1']]]],
]);
