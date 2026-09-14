<?php

declare(strict_types=1);

namespace Bpmore\Scholarly;

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Bpmore\Scholarly\Contracts\PublicationSource;
use Bpmore\Scholarly\Fetching\SourceRegistry;
use Bpmore\Scholarly\Ncbi\EutilsRequest;
use Bpmore\Scholarly\Ncbi\PubmedSource;
use Bpmore\Scholarly\Notifications\NotifyVerifiers;
use Bpmore\Scholarly\Orcid\OrcidSource;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Bpmore\Scholarly\Verification\Verifier;
use Illuminate\Console\Scheduling\Schedule;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
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
        Commands\Fetch::class,
        Commands\Install::class,
        Commands\Notify::class,
        Commands\Calibrate::class,
        Commands\Import::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected $actions = [
        Actions\Confirm::class,
        Actions\Reject::class,
        Actions\Defer::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected $tags = [
        Tags\Scholarly::class,
    ];

    /**
     * @var array<int, class-string>
     */
    protected $modifiers = [
        Modifiers\ScholarlyAuthors::class,
        Modifiers\ScholarlyCitation::class,
    ];

    /**
     * @var array<string, string>
     */
    protected $routes = [
        'actions' => __DIR__.'/../routes/actions.php',
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
        $this->app->bind(PublicationMetadataFetcher::class, function (): Ncbi\EsummaryFetcher {
            return new Ncbi\EsummaryFetcher(
                $this->app->make(EutilsRequest::class),
                (int) config('statamic-scholarly.ncbi.cache_ttl_seconds', 604_800),
                (int) config('statamic-scholarly.ncbi.request_timeout_seconds', 10),
            );
        });

        $this->app->bind(PubmedSource::class, function (): PubmedSource {
            return new PubmedSource(
                $this->app->make(ResearcherFieldMap::class),
                $this->app->make(EutilsRequest::class),
                $this->app->make(PublicationMetadataFetcher::class),
                (bool) config('statamic-scholarly.sources.pubmed.enabled', true),
                (int) config('statamic-scholarly.ncbi.request_timeout_seconds', 10),
            );
        });

        $this->app->bind(PublicationsCollection::class, static fn (): PublicationsCollection => new PublicationsCollection(
            (string) config('statamic-scholarly.publications.collection', 'publications'),
            (string) config('statamic-scholarly.researchers.collection', 'people'),
        ));

        // The fitted model when this site has one, the shipped prior when
        // it does not. Read on every resolve so scholarly:calibrate's result
        // is used by the next job without a restart.
        $this->app->bind(Scoring\Model::class, function (): Scoring\Model {
            return $this->app->make(Scoring\ModelStore::class)->load() ?? Scoring\LogisticModel::prior();
        });

        $this->app->bind(Scoring\ScoreCandidates::class, function (): Scoring\ScoreCandidates {
            $scoring = (array) config('statamic-scholarly.scoring', []);
            $threshold = $scoring['auto_approve_threshold'] ?? null;
            $floor = $scoring['discard_floor'] ?? null;

            return new Scoring\ScoreCandidates(
                $this->app->make(PublicationsCollection::class),
                $this->app->make(Scoring\FingerprintBuilder::class),
                $this->app->make(Scoring\Model::class),
                \array_values(\array_filter((array) config('statamic-scholarly.affiliation_patterns', []), 'is_string')),
                ($scoring['auto_approve'] ?? false) && \is_numeric($threshold) ? (float) $threshold : null,
                \is_numeric($floor) && (float) $floor > 0 ? (float) $floor : null,
            );
        });

        $this->app->bind(Scoring\TrainingSet::class, function (): Scoring\TrainingSet {
            return new Scoring\TrainingSet(
                $this->app->make(PublicationsCollection::class),
                $this->app->make(ResearcherFieldMap::class),
                (string) config('statamic-scholarly.researchers.collection', 'people'),
                \array_values(\array_filter((array) config('statamic-scholarly.affiliation_patterns', []), 'is_string')),
            );
        });

        $this->app->bind(NotifyVerifiers::class, function (): NotifyVerifiers {
            /** @var array<string, mixed> $n */
            $n = (array) config('statamic-scholarly.notifications', []);
            $channels = \array_values(\array_filter((array) ($n['channels'] ?? ['mail']), 'is_string'));

            return new NotifyVerifiers(
                $this->app->make(PublicationsCollection::class),
                (bool) ($n['digest'] ?? true),
                \is_string($n['fallback'] ?? null) ? $n['fallback'] : null,
                $channels === [] ? ['mail'] : $channels,
            );
        });

        $this->app->bind(Publications\ApprovedPublications::class, fn (): Publications\ApprovedPublications => new Publications\ApprovedPublications(
            $this->app->make(PublicationsCollection::class),
            (string) config('statamic-scholarly.researchers.collection', 'people'),
        ));

        // The shipped sources, in the order the snapshot prefers them: a
        // claim (ORCID) before an estimate (PubMed). A host adds a source by
        // tagging its own binding; it is consulted after these.
        $this->app->tag([OrcidSource::class, PubmedSource::class], 'scholarly.sources');

        $this->app->bind(SourceRegistry::class, function (): SourceRegistry {
            /** @var iterable<PublicationSource> $tagged */
            $tagged = $this->app->tagged('scholarly.sources');

            return new SourceRegistry(...$tagged);
        });

        $this->app->bind(OrcidSource::class, function (): OrcidSource {
            /** @var array<string, mixed> $orcid */
            $orcid = (array) config('statamic-scholarly.sources.orcid', []);
            $baseUrl = \is_string($orcid['base_url'] ?? null) && \trim($orcid['base_url']) !== ''
                ? \rtrim(\trim($orcid['base_url']), '/').'/'
                : OrcidSource::DEFAULT_BASE_URL;

            return new OrcidSource(
                $this->app->make(ResearcherFieldMap::class),
                (bool) ($orcid['enabled'] ?? true),
                \is_string($orcid['access_token'] ?? null) ? $orcid['access_token'] : null,
                $baseUrl,
                (int) config('statamic-scholarly.ncbi.request_timeout_seconds', 10),
            );
        });
    }

    public function bootAddon(): void
    {
        Permission::extend(function (): void {
            Permission::group('scholarly', 'Scholarly', function (): void {
                Permission::register(Verifier::PERMISSION)
                    ->label(__('Verify publications'))
                    ->description(__('Confirm, reject and defer candidate publications.'));
            });
        });

        // The review queue is the owned collection's own listing: Statamic's
        // filters narrow it to candidates and the actions above act on rows.
        // Nothing here is a widget of its own.
        Nav::extend(function ($nav): void {
            $handle = (string) config('statamic-scholarly.publications.collection', 'publications');

            $nav->content(__('Publication review'))
                ->route('collections.show', $handle)
                ->icon('clipboard-check')
                ->can(Verifier::PERMISSION);
        });
    }

    /**
     * The nightly run. `schedule` is a frequency name from config, or `false`
     * for a host that runs `scholarly:fetch` from its own scheduler.
     */
    protected function schedule(Schedule $schedule): void
    {
        $frequency = config('statamic-scholarly.schedule', 'daily');

        if (! \is_string($frequency) || \trim($frequency) === '') {
            return;
        }

        // Only a method the scheduler actually has. A typo such as "dailly"
        // would otherwise throw at boot, on every request, on a live site.
        if (! \method_exists(\Illuminate\Console\Scheduling\Event::class, $frequency)) {
            \Illuminate\Support\Facades\Log::warning('Scholarly does not recognise the configured schedule and is running daily instead.', [
                'schedule' => $frequency,
            ]);
            $frequency = 'daily';
        }

        // Notify runs at the same frequency and reads state, so candidates
        // a queued fetch leaves behind are mailed on the next pass at the
        // latest. A synchronous fetch is followed by scholarly:notify by hand.
        $schedule->command('scholarly:fetch')->{$frequency}();
        $schedule->command('scholarly:notify')->{$frequency}();
    }
}
