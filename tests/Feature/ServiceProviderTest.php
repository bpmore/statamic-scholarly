<?php

declare(strict_types=1);

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Bpmore\Scholarly\Ncbi\EsummaryFetcher;
use Bpmore\Scholarly\Ncbi\EutilsRequest;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;

/*
 * The only thing these check is that the provider boots under Testbench and
 * the bindings resolve. Everything with behaviour has its own unit test.
 */

it('publishes its config under the addon handle', function () {
    expect(config('statamic-scholarly'))->toBeArray()
        ->and(config('statamic-scholarly.researchers.fields'))->toBeArray();
});

it('resolves the field map from config rather than from a default', function () {
    config()->set('statamic-scholarly.researchers.fields', ['orcid' => 'host_orcid_handle']);

    $map = app(ResearcherFieldMap::class);

    expect($map->handleFor('orcid'))->toBe('host_orcid_handle');
});

it('binds the metadata fetcher to the addon client by default', function () {
    expect(app(PublicationMetadataFetcher::class))->toBeInstanceOf(EsummaryFetcher::class);
});

it('reads the NCBI identification from config', function () {
    config()->set('statamic-scholarly.ncbi.email', 'ops@example.edu');

    expect(app(EutilsRequest::class))->toBeInstanceOf(EutilsRequest::class);
});
