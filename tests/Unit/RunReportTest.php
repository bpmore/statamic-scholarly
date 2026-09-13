<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\FetchOutcome;
use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Fetching\RunReport;
use Bpmore\Scholarly\Fetching\SourceFailure;
use Bpmore\Scholarly\Fetching\WriteReport;

it('is complete only when every source was configured and completed', function (): void {
    $good = (new FetchOutcome)->withSourceRun('orcid');
    $bad = (new FetchOutcome)->withFailure(new SourceFailure('pubmed', Reason::TransportFailed, 'HTTP 503'));

    expect((new RunReport('r', ['orcid' => $good], [], new WriteReport))->isComplete())->toBeTrue()
        ->and((new RunReport('r', ['orcid' => $good, 'pubmed' => $bad], [], new WriteReport))->isComplete())->toBeFalse()
        ->and((new RunReport('r', ['orcid' => $good], ['pubmed'], new WriteReport))->isComplete())->toBeFalse('an unconfigured source is part of the answer');
});

it('names the failed source and the unconfigured one in its summary', function (): void {
    $bad = (new FetchOutcome)->withFailure(new SourceFailure('pubmed', Reason::TransportFailed, 'HTTP 503'));
    $report = new RunReport('r', ['pubmed' => $bad], ['orcid'], new WriteReport(created: 2), titleMatches: 1);

    $summary = $report->summary();

    expect(str_starts_with($summary, 'PARTIAL'))->toBeTrue()
        ->and(str_contains($summary, 'pubmed: transport failed (HTTP 503)'))->toBeTrue()
        ->and(str_contains($summary, 'not configured: orcid'))->toBeTrue()
        ->and(str_contains($summary, '2 created'))->toBeTrue()
        ->and(str_contains($summary, '1 title match'))->toBeTrue()
        ->and($report->sourcesRun())->toBe([]);
});
