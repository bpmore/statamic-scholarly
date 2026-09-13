<?php

declare(strict_types=1);

use Bpmore\Scholarly\Ncbi\SummaryCandidate;

/**
 * Recorded from NCBI esummary on 2026-09-13: five real rows for the author
 * search "Carberry J" and one deliberately unknown PMID.
 *
 * @return array<string, mixed>
 */
function esummaryRows(): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__.'/../__fixtures__/ncbi/esummary-carberry-j-page-1.json'), true, flags: JSON_THROW_ON_ERROR);

    return $decoded['result'];
}

it('builds a candidate from a real row', function (): void {
    $candidate = SummaryCandidate::fromRow(esummaryRows()['40788977']);

    expect($candidate->sourceKey)->toBe('pubmed')
        ->and($candidate->pmid)->toBe('40788977')
        ->and($candidate->doi)->toBe('10.1161/CIRCULATIONAHA.125.074645')
        ->and($candidate->title)->toBe('Intramyocardial Hemorrhage After Myocardial Infarction: A Prognostic Outcome for Clinical Practice and Trials.')
        ->and($candidate->journal)->toBe('Circulation')
        ->and($candidate->publicationDate)->toBe('2025-08-12')
        ->and($candidate->authors[0])->toBe(['name' => 'Berry C'])
        ->and($candidate->authors[1])->toBe(['name' => 'Carberry J'])
        ->and($candidate->externalUrls)->toBe(['https://pubmed.ncbi.nlm.nih.gov/40788977/'])
        ->and($candidate->hardKeys())->toBe(['doi:10.1161/circulationaha.125.074645', 'pmid:40788977']);
});

it('prefers the full journal name over the abbreviation', function (): void {
    expect(SummaryCandidate::fromRow(esummaryRows()['41640490'])->journal)->toBe('Canadian prosthetics & orthotics journal');
});

it('renders pubdate at the precision NCBI has and never invents a day', function (string $pubdate, ?string $expected): void {
    expect(SummaryCandidate::fromRow(['uid' => '1', 'pubdate' => $pubdate])->publicationDate)->toBe($expected);
})->with([
    'full' => ['2025 Aug 12', '2025-08-12'],
    'year and month, from the recorded row' => ['2026 Nov', '2026-11'],
    'year only, from the recorded row' => ['2025', '2025'],
    'month range' => ['2019 Nov-Dec', '2019-11'],
    'day range' => ['2020 Mar 24-31', '2020-03-24'],
    'season' => ['2020 Spring', '2020'],
    'garbage' => ['n.d.', null],
]);

it('takes the DOI from elocationid when articleids has none', function (): void {
    $row = ['uid' => '1', 'articleids' => [['idtype' => 'pubmed', 'value' => '1']], 'elocationid' => 'doi: 10.1016/0006-2944(75)90147-7'];

    expect(SummaryCandidate::fromRow($row)->doi)->toBe('10.1016/0006-2944(75)90147-7');
});

it('leaves the DOI null rather than guessing when the row has none', function (): void {
    expect(SummaryCandidate::fromRow(['uid' => '1', 'articleids' => [['idtype' => 'pubmed', 'value' => '1']]])->doi)->toBeNull();
});

it('records names only, since esummary carries no affiliation or ORCID', function (): void {
    $candidate = SummaryCandidate::fromRow(esummaryRows()['42392556']);

    expect($candidate->authors)->not->toBe([]);

    foreach ($candidate->authors as $author) {
        expect(array_keys($author))->toBe(['name']);
    }
});
