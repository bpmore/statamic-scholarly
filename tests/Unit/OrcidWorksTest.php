<?php

declare(strict_types=1);

use Bpmore\Scholarly\Orcid\OrcidWorks;
use Bpmore\Scholarly\Orcid\UnexpectedOrcidResponse;
use Bpmore\Scholarly\Publications\CandidatePublication;

/**
 * The recorded response is the real public record of Josiah Carberry, ORCID's
 * fictional test persona, fetched 2026-09-13. Real rather than hand-written so
 * the shape being parsed is the shape ORCID actually sends.
 *
 * @return array<string, mixed>
 */
function carberryWorks(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/../__fixtures__/orcid/0000-0002-1825-0097-works.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * A minimal group in ORCID's shape, for the cases the recorded record lacks.
 *
 * @param  list<array{type: string, value: string, relationship?: string}>  $ids
 * @param  array<string, mixed>  $summary
 * @return array<string, mixed>
 */
function orcidGroup(array $ids, array $summary = [], int $modified = 1_600_000_000_000): array
{
    $externalIds = array_map(static fn (array $id): array => [
        'external-id-type' => $id['type'],
        'external-id-value' => $id['value'],
        'external-id-relationship' => $id['relationship'] ?? 'self',
    ], $ids);

    return [
        'last-modified-date' => ['value' => $modified],
        'external-ids' => ['external-id' => $externalIds],
        'work-summary' => [
            [
                'put-code' => 1,
                'title' => ['title' => ['value' => 'A work']],
                'external-ids' => ['external-id' => $externalIds],
                'publication-date' => null,
                'journal-title' => null,
                'url' => null,
                'display-index' => '0',
                ...$summary,
            ],
        ],
    ];
}

it('turns each ORCID group into one candidate carrying the group DOI', function (): void {
    $candidates = OrcidWorks::candidates(carberryWorks());

    expect($candidates)->toHaveCount(6)
        ->each->toBeInstanceOf(CandidatePublication::class);

    $dois = array_map(static fn (CandidatePublication $c): ?string => $c->doi, $candidates);

    expect($dois)->toBe([
        '10.5555/12345680',
        '10.5555/666655554444',
        '10.5555/987654321',
        '10.5555/12345679',
        '10.5555/12345678',
        '10.1109/TPS.1987.4316723',
    ]);

    foreach ($candidates as $candidate) {
        expect($candidate->sourceKey)->toBe('orcid')
            ->and($candidate->pmid)->toBeNull();
    }
});

it('does not emit one candidate per summary when a group has several', function (): void {
    // The first Carberry group has two summaries of the same work, one from
    // Crossref and one the researcher typed in. Two candidates here would be
    // a duplicate the de-duplicator then has to catch; one is the answer.
    $decoded = carberryWorks();

    expect($decoded['group'][0]['work-summary'])->toHaveCount(2);

    $candidates = OrcidWorks::candidates(['group' => [$decoded['group'][0]]]);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->title)->toBe('A Methodology for the Emulation of Architecture')
        ->and($candidates[0]->publicationDate)->toBe('2012');
});

it('reads journal and url from the summary when the record has them', function (): void {
    $last = OrcidWorks::candidates(carberryWorks())[5];

    expect($last->title)->toBe('Bulk and surface plasmons in artificially structured materials')
        ->and($last->journal)->toBe('IEEE Transactions on Plasma Science')
        ->and($last->publicationDate)->toBe('1987')
        ->and($last->externalUrls)->toBe(['http://www.scopus.com/inward/record.url?eid=2-s2.0-0023398608&partnerID=MN8TOARS']);
});

it('keeps only groups modified at or after the since instant', function (): void {
    // Five Carberry groups were last touched in 2017; one in 2022.
    $since = (int) (new DateTimeImmutable('2020-01-01T00:00:00Z'))->getTimestamp() * 1000;

    $candidates = OrcidWorks::candidates(carberryWorks(), $since);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->doi)->toBe('10.1109/TPS.1987.4316723');

    $exact = 1653413908337;

    expect(OrcidWorks::candidates(carberryWorks(), $exact))->toHaveCount(1)
        ->and(OrcidWorks::candidates(carberryWorks(), $exact + 1))->toHaveCount(0);
});

it('carries a PMID when ORCID reports one', function (): void {
    $candidates = OrcidWorks::candidates(['group' => [
        orcidGroup([['type' => 'pmid', 'value' => '32208485'], ['type' => 'doi', 'value' => '10.1001/jama.2020.1585']]),
    ]]);

    expect($candidates[0]->pmid)->toBe('32208485')
        ->and($candidates[0]->doi)->toBe('10.1001/jama.2020.1585')
        ->and($candidates[0]->hardKeys())->toBe(['doi:10.1001/jama.2020.1585', 'pmid:32208485']);
});

it('ignores a part-of DOI rather than keying a chapter on its book', function (): void {
    // Keying two chapters on the book's DOI would merge them into one entry
    // that looks perfectly plausible. Nobody would notice.
    $chapterOne = orcidGroup([['type' => 'doi', 'value' => '10.1000/book', 'relationship' => 'part-of']]);
    $chapterTwo = orcidGroup([['type' => 'doi', 'value' => '10.1000/book', 'relationship' => 'part-of']]);

    $candidates = OrcidWorks::candidates(['group' => [$chapterOne, $chapterTwo]]);

    expect($candidates)->toHaveCount(2);

    foreach ($candidates as $candidate) {
        expect($candidate->doi)->toBeNull()
            ->and($candidate->hardKey())->toBeNull();
    }
});

it('picks the summary the researcher prefers to display', function (): void {
    $group = orcidGroup([['type' => 'doi', 'value' => '10.1000/x']]);
    $group['work-summary'] = [
        ['title' => ['title' => ['value' => 'Automated import title']], 'display-index' => '0'],
        ['title' => ['title' => ['value' => 'Title the researcher chose']], 'display-index' => '3'],
        ['title' => ['title' => ['value' => 'Another import']], 'display-index' => '1'],
    ];

    expect(OrcidWorks::candidates(['group' => [$group]])[0]->title)->toBe('Title the researcher chose');
});

it('renders a partial date at the precision ORCID has and never invents a day', function (array $date, ?string $expected): void {
    $group = orcidGroup([], ['publication-date' => $date]);

    expect(OrcidWorks::candidates(['group' => [$group]])[0]->publicationDate)->toBe($expected);
})->with([
    'year only' => [['year' => ['value' => '2012'], 'month' => null, 'day' => null], '2012'],
    'year and month' => [['year' => ['value' => '2012'], 'month' => ['value' => '3'], 'day' => null], '2012-03'],
    'full' => [['year' => ['value' => '2012'], 'month' => ['value' => '03'], 'day' => ['value' => '7']], '2012-03-07'],
    'day without month' => [['year' => ['value' => '2012'], 'month' => null, 'day' => ['value' => '7']], '2012'],
    'no year' => [['year' => null, 'month' => ['value' => '3'], 'day' => null], null],
]);

it('returns no candidates for a record with no works', function (): void {
    expect(OrcidWorks::candidates(['group' => [], 'path' => '/0000-0002-1825-0097/works']))->toBe([]);
});

it('refuses a response that is not a works listing instead of reading it as empty', function (array $decoded): void {
    expect(static fn () => OrcidWorks::candidates($decoded))->toThrow(UnexpectedOrcidResponse::class);
})->with([
    'no group key' => [[]],
    'an error body' => [['response-code' => 404, 'developer-message' => '404 Not found']],
    'group is not a list' => [['group' => 'x']],
    'group without a summary' => [['group' => [['external-ids' => ['external-id' => []], 'work-summary' => []]]]],
]);


it('preserves scoped ORCID identities through enrichment and preferred-summary changes', function (): void {
    $group = orcidGroup([], ['put-code' => 12]);
    $other = $group['work-summary'][0];
    $other['put-code'] = '34';
    $other['display-index'] = '2';
    $group['work-summary'][] = $other;
    $record = 'https://pub.orcid.org/v3.0/0000-0002-1825-0097';
    $candidate = OrcidWorks::candidates(['group' => [$group]], recordUri: $record)[0];
    expect($candidate->sourceRecordIds)->toBe([$record.'/work/12', $record.'/work/34'])
        ->and($candidate->withDetails([['name' => 'Author']], 'Abstract')->sourceRecordIds)->toBe($candidate->sourceRecordIds);
    $group['work-summary'][0]['display-index'] = '3';
    expect(OrcidWorks::candidates(['group' => [$group]], recordUri: $record)[0]->sourceRecordIds)->toBe($candidate->sourceRecordIds);
    $different = OrcidWorks::candidates(['group' => [$group]], recordUri: 'https://pub.orcid.org/v3.0/another-person')[0];
    expect(array_intersect($candidate->sourceRecordIds, $different->sourceRecordIds))->toBe([]);
    $sandbox = OrcidWorks::candidates(['group' => [$group]], recordUri: 'https://pub.sandbox.orcid.org/v3.0/0000-0002-1825-0097')[0];
    expect(array_intersect($candidate->sourceRecordIds, $sandbox->sourceRecordIds))->toBe([]);
});

it('does not invent source identity without a record URI or a valid put-code', function (): void {
    $group = orcidGroup([], ['put-code' => null]);
    expect(OrcidWorks::candidates(['group' => [$group]], recordUri: 'https://pub.orcid.org/v3.0/person')[0]->sourceRecordIds)->toBe([])
        ->and(OrcidWorks::candidates(['group' => [orcidGroup([])]])[0]->sourceRecordIds)->toBe([]);
});
