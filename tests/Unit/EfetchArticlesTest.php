<?php

declare(strict_types=1);

use Bpmore\Scholarly\Ncbi\EfetchArticles;
use Bpmore\Scholarly\Ncbi\UnexpectedNcbiResponse;

/**
 * Recorded from NCBI efetch on 2026-09-13 for two real PMIDs and one that
 * does not exist. The unknown one is simply absent from the set.
 */
function efetchXml(): string
{
    return (string) file_get_contents(__DIR__.'/../__fixtures__/ncbi/efetch-carberry-j-two-articles.xml');
}

it('reads authors with affiliation and ORCID iD, in E-summary name form', function (): void {
    $details = EfetchArticles::parse(efetchXml(), ['40788977', '40842283', '99999999']);

    expect(array_keys($details))->toBe([40788977, 40842283, 99999999])
        ->and($details['99999999'])->toBeNull()
        ->and($details['40788977']['authors'][0]['name'])->toBe('Berry C')
        ->and($details['40788977']['authors'][0]['orcid'])->toBe('0000-0002-4547-8636')
        ->and($details['40788977']['authors'][0]['affiliation'])->toStartWith('West of Scotland Heart and Lung Centre')
        ->and(str_contains($details['40788977']['authors'][0]['affiliation'], '; British Heart Foundation'))->toBeTrue('two affiliations are joined')
        ->and($details['40788977']['authors'][2]['orcid'])->toBeNull()
        ->and($details['40788977']['abstract'])->toBeNull('this record has no abstract');
});

it('joins a structured abstract with its section labels, in order', function (): void {
    $abstract = EfetchArticles::parse(efetchXml(), ['40842283'])['40842283']['abstract'];

    expect($abstract)->toStartWith('AIMS: The recently proposed')
        ->and(str_contains($abstract, "\n\nMETHODS AND RESULTS: "))->toBeTrue()
        ->and(str_contains($abstract, "\n\nCONCLUSION: "))->toBeTrue();
});

it('keeps a collective author by its collective name', function (): void {
    $xml = '<?xml version="1.0"?><PubmedArticleSet><PubmedArticle><MedlineCitation><PMID>1</PMID><Article>'
        .'<AuthorList><Author><CollectiveName>GBD 2019 Collaborators</CollectiveName></Author><Author><LastName>Smith</LastName><Initials>J</Initials></Author></AuthorList>'
        .'</Article></MedlineCitation></PubmedArticle></PubmedArticleSet>';

    $authors = EfetchArticles::parse($xml, ['1'])['1']['authors'];

    expect(array_column($authors, 'name'))->toBe(['GBD 2019 Collaborators', 'Smith J']);
});

it('keeps only a well formed ORCID iD, whatever form PubMed recorded it in', function (string $recorded, ?string $expected): void {
    $xml = '<?xml version="1.0"?><PubmedArticleSet><PubmedArticle><MedlineCitation><PMID>1</PMID><Article>'
        .'<AuthorList><Author><LastName>A</LastName><Initials>B</Initials><Identifier Source="ORCID">'.$recorded.'</Identifier></Author></AuthorList>'
        .'</Article></MedlineCitation></PubmedArticle></PubmedArticleSet>';

    expect(EfetchArticles::parse($xml, ['1'])['1']['authors'][0]['orcid'])->toBe($expected);
})->with([
    'bare' => ['0000-0002-1825-0097', '0000-0002-1825-0097'],
    'url' => ['https://orcid.org/0000-0002-1825-009X', '0000-0002-1825-009X'],
    'lower x' => ['0000-0002-1825-009x', '0000-0002-1825-009X'],
    'garbage' => ['orcid pending', null],
    'too short' => ['0000-0002-1825', null],
]);

it('refuses a body that is not an article set instead of reading it as empty', function (string $body): void {
    expect(static fn () => EfetchArticles::parse($body, ['1']))->toThrow(UnexpectedNcbiResponse::class);
})->with([
    'html' => ['<html><body>Service unavailable</body></html>'],
    'empty' => [''],
    'json' => ['{"result":{}}'],
    'wrong root' => ['<?xml version="1.0"?><eSummaryResult></eSummaryResult>'],
]);
