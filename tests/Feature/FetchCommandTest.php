<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Fetching\FetchOutcome;
use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Fetching\SourceFailure;
use Bpmore\Scholarly\Fetching\SourceRegistry;
use Bpmore\Scholarly\Jobs\FetchPublications;
use Bpmore\Scholarly\Publications\CandidatePublication;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Tests\Support\FakeSource;
use Illuminate\Support\Facades\Bus;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

const CMD_RESEARCHERS = 'faculty';

beforeEach(function (): void {
    config()->set('statamic-scholarly.researchers.collection', CMD_RESEARCHERS);
    config()->set('statamic-scholarly.publications.collection', 'research_outputs');
    Collection::make(CMD_RESEARCHERS)->save();
});

function cmdResearcher(string $title, string $slug): \Statamic\Contracts\Entries\Entry
{
    $entry = Entry::make()->collection(CMD_RESEARCHERS)->slug($slug)->data(['title' => $title]);
    $entry->save();

    return $entry;
}

function bindSources(FakeSource ...$sources): void
{
    app()->instance(SourceRegistry::class, new SourceRegistry(...$sources));
}

it('queues one job per researcher by default', function (): void {
    Bus::fake();
    $a = cmdResearcher('A', 'a');
    $b = cmdResearcher('B', 'b');
    bindSources(new FakeSource('orcid', new FetchOutcome));

    $this->artisan('scholarly:fetch')->assertSuccessful();

    Bus::assertDispatchedTimes(FetchPublications::class, 2);
    Bus::assertDispatched(FetchPublications::class, fn (FetchPublications $job) => $job->researcherId === $a->id() && $job->sourceKey === null && $job->since === null);
    Bus::assertDispatched(FetchPublications::class, fn (FetchPublications $job) => $job->researcherId === $b->id());
});

it('queues for one researcher by slug, carrying the source and since options', function (): void {
    Bus::fake();
    cmdResearcher('A', 'a');
    $b = cmdResearcher('B', 'b');
    bindSources(new FakeSource('orcid', new FetchOutcome), new FakeSource('pubmed', new FetchOutcome));

    $this->artisan('scholarly:fetch', ['researcher' => 'b', '--source' => 'pubmed', '--since' => '2024-01-01'])->assertSuccessful();

    Bus::assertDispatchedTimes(FetchPublications::class, 1);
    Bus::assertDispatched(FetchPublications::class, fn (FetchPublications $job) => $job->researcherId === $b->id() && $job->sourceKey === 'pubmed' && $job->since === '2024-01-01');
});

it('refuses an unknown source before queueing anything', function (): void {
    Bus::fake();
    cmdResearcher('A', 'a');
    bindSources(new FakeSource('orcid', new FetchOutcome));

    $this->artisan('scholarly:fetch', ['--source' => 'orcd'])->assertFailed();

    Bus::assertNotDispatched(FetchPublications::class);
});

it('refuses a researcher it cannot find, and an entry from another collection', function (): void {
    Bus::fake();
    Collection::make('pages')->save();
    $page = Entry::make()->collection('pages')->slug('about')->data(['title' => 'About']);
    $page->save();
    bindSources(new FakeSource('orcid', new FetchOutcome));

    $this->artisan('scholarly:fetch', ['researcher' => 'nobody'])->assertFailed();
    $this->artisan('scholarly:fetch', ['researcher' => $page->id()])->assertFailed();

    Bus::assertNotDispatched(FetchPublications::class);
});

it('runs inline with --sync and prints each report, failing when any run was partial', function (): void {
    $a = cmdResearcher('Ada', 'ada');
    bindSources(
        new FakeSource('orcid', (new FetchOutcome)->withCandidates(new CandidatePublication('orcid', title: 'P', doi: '10.1000/p'))->withSourceRun('orcid')),
        new FakeSource('pubmed', (new FetchOutcome)->withFailure(new SourceFailure('pubmed', Reason::TransportFailed, 'HTTP 503'))),
    );

    $this->artisan('scholarly:fetch', ['--sync' => true])
        // One expectation: both live on one line, and each expectation
        // consumes the line it matched.
        ->expectsOutputToContain('Ada: PARTIAL: 1 candidate(s) from orcid; 1 created, 0 refreshed, 0 approved kept, 0 rejected suppressed; failed pubmed: transport failed (HTTP 503)')
        ->assertFailed();

    expect(Entry::query()->where('collection', 'research_outputs')->get()->filter(fn ($e) => in_array($a->id(), (array) $e->get('researchers'), true))->count())->toBe(1);
});

it('the job resolves the researcher by id and runs the pipeline', function (): void {
    $a = cmdResearcher('Ada', 'ada');
    $source = new FakeSource('orcid', (new FetchOutcome)->withSourceRun('orcid'));
    bindSources($source);

    (new FetchPublications($a->id(), null, '2023-05-05'))->handle(app(FetchForResearcher::class));

    expect($source->calls)->toBe([['researcher' => $a->id(), 'since' => '2023-05-05']]);
});

it('the job skips a researcher that no longer exists rather than failing', function (): void {
    $source = new FakeSource('orcid', (new FetchOutcome)->withSourceRun('orcid'));
    bindSources($source);

    (new FetchPublications('gone'))->handle(app(FetchForResearcher::class));

    expect($source->calls)->toBe([]);
});

it('installs the owned collection under the configured handle', function (): void {
    $this->artisan('scholarly:install')->expectsOutputToContain('research_outputs')->assertSuccessful();

    expect(Collection::findByHandle('research_outputs'))->not->toBeNull()
        ->and(app(PublicationsCollection::class)->handle())->toBe('research_outputs');
});
