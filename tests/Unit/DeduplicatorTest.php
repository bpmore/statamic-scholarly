<?php

declare(strict_types=1);

use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\Deduplicator;

it('joins a DOI-only source to a PMID-only source through a candidate carrying both', function (): void {
    // This is the case the whole design turns on. ORCID reports DOIs, PubMed
    // reports PMIDs, and neither alone can tell they are the same paper.
    $merged = (new Deduplicator())->merge([
        new CandidatePublication(sourceKey: 'orcid', title: 'Paper One', doi: 'https://doi.org/10.1001/JAMA.2020.1585'),
        new CandidatePublication(sourceKey: 'pubmed', title: 'Paper One', pmid: '12345'),
        new CandidatePublication(sourceKey: 'scopus', title: 'Paper One', doi: '10.1001/jama.2020.1585', pmid: 12345),
    ]);

    expect($merged)->toHaveCount(1);
    expect($merged[0]->corroborationCount())->toBe(3);
    expect($merged[0]->doi)->toBe('10.1001/jama.2020.1585');
    expect($merged[0]->pmid)->toBe('12345');
});

it('keeps candidates apart when nothing joins them', function (): void {
    $merged = (new Deduplicator())->merge([
        new CandidatePublication(sourceKey: 'orcid', title: 'Solo A', doi: '10.1038/s41586-020-2649-2'),
        new CandidatePublication(sourceKey: 'pubmed', title: 'Solo B', pmid: '999'),
    ]);

    expect($merged)->toHaveCount(2);
});

it('flags a title collision for review instead of merging it', function (): void {
    // An erratum and the paper it corrects share a title and are different
    // works. Merging them destroys one.
    $merged = (new Deduplicator())->merge([
        new CandidatePublication(sourceKey: 'orcid', title: 'Shared Title', doi: '10.1001/jama.2021.0001'),
        new CandidatePublication(sourceKey: 'orcid', title: 'Shared Title.', doi: '10.1001/jama.2021.0002'),
    ]);

    expect($merged)->toHaveCount(2);
    expect($merged[0]->titleMatches)->toHaveCount(1);
});

it('gives every candidate with no identifier a group of its own', function (): void {
    $merged = (new Deduplicator())->merge([
        new CandidatePublication(sourceKey: 'bibtex', title: 'Untitled Import'),
        new CandidatePublication(sourceKey: 'bibtex', title: 'Another'),
    ]);

    expect($merged)->toHaveCount(2);
});
