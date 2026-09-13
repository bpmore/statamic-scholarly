<?php

declare(strict_types=1);

use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\MergedPublication;
use Bpmore\Scholarly\Publications\Snapshot;

it('prefers the first source for scalars and the fullest list for authors', function (): void {
    $orcid = new CandidatePublication('orcid', title: 'From ORCID', doi: '10.1000/x', journal: null, publicationDate: '2020', externalUrls: ['https://a']);
    $pubmed = new CandidatePublication('pubmed', title: 'From PubMed.', doi: '10.1000/x', pmid: '1', journal: 'J', publicationDate: '2020-03-01', authors: [['name' => 'A B'], ['name' => 'C D']], externalUrls: ['https://a', 'https://b']);

    $values = Snapshot::fromMerged(new MergedPublication([$orcid, $pubmed], ['orcid', 'pubmed'], '10.1000/x', '1'));

    expect($values['title'])->toBe('From ORCID')
        ->and($values['journal'])->toBe('J')
        ->and($values['publication_date'])->toBe('2020')
        ->and($values['doi'])->toBe('10.1000/x')
        ->and($values['pmid'])->toBe('1')
        ->and($values['authors'])->toBe([
            ['name' => 'A B', 'affiliation' => null, 'orcid' => null],
            ['name' => 'C D', 'affiliation' => null, 'orcid' => null],
        ])
        ->and($values['external_urls'])->toBe(['https://a', 'https://b'])
        ->and($values['source_apis'])->toBe(['orcid', 'pubmed']);
});

it('labels an untitled paper by its identifier rather than a placeholder', function (): void {
    $byDoi = new MergedPublication([new CandidatePublication('orcid', doi: '10.1000/x')], ['orcid'], '10.1000/x');
    $byPmid = new MergedPublication([new CandidatePublication('pubmed', pmid: '7')], ['pubmed'], null, '7');
    $nothing = new MergedPublication([new CandidatePublication('pubmed', title: '  ')], ['pubmed']);

    expect(Snapshot::fromMerged($byDoi)['title'])->toBe('doi:10.1000/x')
        ->and(Snapshot::fromMerged($byPmid)['title'])->toBe('pmid:7')
        ->and(Snapshot::fromMerged($nothing)['title'])->toBe('untitled');
});
