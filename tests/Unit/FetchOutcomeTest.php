<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\FetchOutcome;
use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Fetching\SourceFailure;
use Bpmore\Scholarly\Publications\CandidatePublication;

it('separates a complete run from a partial one', function (): void {
    $complete = (new FetchOutcome())
        ->withSourceRun('orcid')
        ->withCandidates(new CandidatePublication(sourceKey: 'orcid', title: 'X', doi: '10.1001/jama.2022.0003'));

    expect($complete->isComplete())->toBeTrue();

    $partial = $complete->withFailure(new SourceFailure('pubmed', Reason::TransportFailed, 'HTTP 503'));

    expect($partial->isComplete())->toBeFalse();
    expect(str_starts_with($partial->summary(), 'PARTIAL:'))->toBeTrue();
});

it('distinguishes an empty answer from a run where nothing could look', function (): void {
    // The failure this addon exists to refuse: zero publications is
    // indistinguishable from a researcher who has none unless the run says so.
    $nothingRan = (new FetchOutcome())
        ->withFailure(new SourceFailure('orcid', Reason::RefusedLocally, 'no contact email'));

    expect($nothingRan->isEmptyBecauseNothingRan())->toBeTrue();

    $genuinelyEmpty = new FetchOutcome(sourcesRun: ['orcid']);

    expect($genuinelyEmpty->isEmptyBecauseNothingRan())->toBeFalse();
    expect($genuinelyEmpty->isComplete())->toBeTrue();
});
