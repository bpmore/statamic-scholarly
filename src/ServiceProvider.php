<?php

declare(strict_types=1);

namespace Bpmore\Scholarly;

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Bpmore\Scholarly\Ncbi\EutilsRequest;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Statamic\Providers\AddonServiceProvider;

/**
 * Wiring only. Everything with behaviour is a plain class this binds.
 *
 * Statamic discovers anything in `src/Listeners` and binds it to the event
 * named by its first type hint, so listeners are not registered here.
 */
class ServiceProvider extends AddonServiceProvider
{
    protected $config = true;

    protected $viewNamespace = 'scholarly';

    /**
     * @var array<int, class-string>
     */
    protected $commands = [
        // Commands\Fetch::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected $tags = [
        // Tags\ScholarlyTags::class,
    ];

    public function register()
    {
        parent::register();

        // Resolved lazily: the addon's config is merged during boot, and a
        // binding evaluated at register time would read an empty array.
        $this->app->bind(
            ResearcherFieldMap::class,
            static fn (): ResearcherFieldMap => ResearcherFieldMap::fromConfig(
                (array) config('statamic-scholarly.researchers.fields', [])
            )
        );

        $this->app->bind(EutilsRequest::class, static function (): EutilsRequest {
            /** @var array<string, mixed> $ncbi */
            $ncbi = (array) config('statamic-scholarly.ncbi', []);

            return new EutilsRequest(
                \is_string($ncbi['tool'] ?? null) ? $ncbi['tool'] : 'statamic-scholarly',
                \is_string($ncbi['email'] ?? null) ? $ncbi['email'] : null,
                \is_string($ncbi['api_key'] ?? null) ? $ncbi['api_key'] : null,
            );
        });

        // Bound to the addon's own client by default. A host with a PubMed
        // client of its own rebinds this and keeps one cache keyspace.
        $this->app->bind(PublicationMetadataFetcher::class, Ncbi\EsummaryFetcher::class);
    }
}
