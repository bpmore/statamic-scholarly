<?php

declare(strict_types=1);

use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Orcid\OrcidSource;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/*
 * The field handle here is deliberately NOT the shipped default (`orcid_id`).
 * A source that reads the default by name passes against the default and
 * fails on every other site, silently, as a researcher with no ORCID.
 */
const ORCID_HANDLE = 'host_orcid_field';

const CARBERRY = '0000-0002-1825-0097';

function orcidSource(array $overrides = []): OrcidSource
{
    config()->set('statamic-scholarly.researchers.fields', ['orcid' => ORCID_HANDLE]);
    config()->set('statamic-scholarly.sources.orcid', [
        'enabled' => true,
        'access_token' => null,
        'base_url' => 'https://pub.orcid.org/v3.0/',
        ...$overrides,
    ]);

    return app(OrcidSource::class);
}

function researcher(array $data): \Statamic\Contracts\Entries\Entry
{
    return Entry::make()->collection('faculty')->data($data);
}

beforeEach(function (): void {
    // Not the shipped default collection (`people`) either, for the same reason.
    Collection::make('faculty')->save();
});

function carberryBody(): string
{
    return (string) file_get_contents(__DIR__.'/../__fixtures__/orcid/0000-0002-1825-0097-works.json');
}

it('is configured only when enabled and the host has mapped an ORCID field', function (): void {
    expect(orcidSource()->isConfigured())->toBeTrue()
        ->and(orcidSource(['enabled' => false])->isConfigured())->toBeFalse();

    config()->set('statamic-scholarly.researchers.fields', []);

    expect(app(OrcidSource::class)->isConfigured())->toBeFalse();
});

it('reads the ORCID iD from the host-mapped field and asks ORCID for that record', function (): void {
    Http::fake(['pub.orcid.org/v3.0/'.CARBERRY.'/works' => Http::response(carberryBody(), 200)]);

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]));

    expect($outcome->isComplete())->toBeTrue()
        ->and($outcome->sourcesRun)->toBe(['orcid'])
        ->and($outcome->candidates)->toHaveCount(6)
        ->and($outcome->candidates[0]->doi)->toBe('10.5555/12345680');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://pub.orcid.org/v3.0/'.CARBERRY.'/works'
        && $request->hasHeader('Accept', 'application/json')
        && ! $request->hasHeader('Authorization'));
});

it('ignores the shipped default handle when the host mapped a different one', function (): void {
    Http::fake();

    // The value sits under the default handle, which this host does not use.
    $outcome = orcidSource()->fetchForResearcher(researcher(['orcid_id' => CARBERRY]));

    Http::assertNothingSent();

    expect($outcome->isComplete())->toBeFalse()
        ->and($outcome->isEmptyBecauseNothingRan())->toBeTrue()
        ->and($outcome->failures[0]->reason)->toBe(Reason::NotConfigured)
        ->and(str_contains($outcome->failures[0]->detail, ORCID_HANDLE))->toBeTrue('the failure names the field to fill in');
});

it('sends the access token as a bearer when one is configured', function (): void {
    Http::fake(['*' => Http::response(carberryBody(), 200)]);

    orcidSource(['access_token' => 'read-public-token'])->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]));

    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer read-public-token'));
});

it('uses the configured base url, so the sandbox can be pointed at', function (): void {
    Http::fake(['pub.sandbox.orcid.org/*' => Http::response(carberryBody(), 200)]);

    orcidSource(['base_url' => 'https://pub.sandbox.orcid.org/v3.0'])->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]));

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://pub.sandbox.orcid.org/v3.0/'.CARBERRY.'/works');
});

it('refuses a malformed ORCID iD before sending anything', function (): void {
    Http::fake();

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => '0000-0002-1825-009x']));

    Http::assertNothingSent();

    expect($outcome->failures[0]->reason)->toBe(Reason::RefusedLocally)
        ->and($outcome->sourcesRun)->toBe([]);
});

it('reports a 404 as an unknown identifier, not as a researcher with no works', function (): void {
    Http::fake(['*' => Http::response('{"response-code":404,"developer-message":"404 Not found"}', 404)]);

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => '0000-0002-1825-0098']));

    expect($outcome->candidates)->toBe([])
        ->and($outcome->isComplete())->toBeFalse()
        ->and($outcome->failures[0]->reason)->toBe(Reason::UnknownIdentifier)
        ->and(str_contains($outcome->failures[0]->detail, ORCID_HANDLE))->toBeTrue('the failure names the field to check');
});

it('reports a rate limit or outage as a transport failure', function (int $status): void {
    Http::fake(['*' => Http::response('', $status)]);

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]));

    expect($outcome->isEmptyBecauseNothingRan())->toBeTrue()
        ->and($outcome->failures[0]->reason)->toBe(Reason::TransportFailed);
})->with([429, 503]);

it('reports an unreachable ORCID as a transport failure rather than throwing', function (): void {
    Http::fake(static fn () => throw new ConnectionException('cURL error 28: timed out'));

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]));

    expect($outcome->failures[0]->reason)->toBe(Reason::TransportFailed)
        ->and($outcome->failures[0]->detail)->toBe('cURL error 28: timed out');
});

it('reports a body it cannot read as an unexpected response', function (string $body): void {
    Http::fake(['*' => Http::response($body, 200)]);

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]));

    expect($outcome->candidates)->toBe([])
        ->and($outcome->failures[0]->reason)->toBe(Reason::UnexpectedResponse);
})->with([
    'html' => ['<html>maintenance</html>'],
    'json without works' => ['{"path":"/x/works"}'],
]);

it('distinguishes a record with no works from a run that could not look', function (): void {
    Http::fake(['*' => Http::response('{"last-modified-date":null,"group":[],"path":"/'.CARBERRY.'/works"}', 200)]);

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]));

    expect($outcome->candidates)->toBe([])
        ->and($outcome->isComplete())->toBeTrue()
        ->and($outcome->isEmptyBecauseNothingRan())->toBeFalse()
        ->and($outcome->sourcesRun)->toBe(['orcid']);
});

it('passes since through as a last-modified filter', function (): void {
    Http::fake(['*' => Http::response(carberryBody(), 200)]);

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]), '2020-01-01T00:00:00Z');

    expect($outcome->candidates)->toHaveCount(1)
        ->and($outcome->isComplete())->toBeTrue();
});

it('refuses a since value it cannot parse instead of fetching everything', function (): void {
    Http::fake();

    $outcome = orcidSource()->fetchForResearcher(researcher([ORCID_HANDLE => CARBERRY]), 'last tuesday-ish');

    Http::assertNothingSent();

    expect($outcome->failures[0]->reason)->toBe(Reason::RefusedLocally);
});
