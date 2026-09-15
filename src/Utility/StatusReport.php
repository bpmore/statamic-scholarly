<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Utility;

use Bpmore\Scholarly\Contracts\PublicationSource;
use Bpmore\Scholarly\Fetching\FetchForResearcher;
use Bpmore\Scholarly\Fetching\SourceRegistry;
use Bpmore\Scholarly\Notifications\NotifyVerifiers;
use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Bpmore\Scholarly\Scoring\ModelStore;
use Bpmore\Scholarly\Verification\WaitingCount;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Entries\QueryBuilder;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * What the addon knows about this site, for the page under Tools.
 *
 * Read-only, and nothing here fetches: every number is read from entries the
 * last run wrote, from config, or from the fitted model on disk. A page that
 * queried ORCID or PubMed while loading would be a network call on a render
 * path, which this repository forbids, and it would be answering a different
 * question. The question here is "what did the last run say, and is the next
 * one set up to answer", not "what is true right now".
 *
 * Where a thing could not be known, the report says so rather than showing a
 * zero. A researcher with no identifiers is not one with no publications; a
 * run that was partial is not a run that found nothing; a source that is off
 * is not one that looked and saw nothing.
 */
final class StatusReport
{
    /**
     * @param  array<string, mixed>  $config  The whole `statamic-scholarly` config array
     */
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly ResearcherFieldMap $fields,
        private readonly PublicationsCollection $publications,
        private readonly WaitingCount $waiting,
        private readonly ModelStore $models,
        private readonly array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'researchers' => $this->researchers(),
            'identifiers' => $this->identifiers(),
            'sources' => $this->sources(),
            'ncbi' => $this->ncbi(),
            'queue' => $this->queue(),
            'scoring' => $this->scoring(),
            'notifications' => $this->notifications(),
            'schedule' => $this->schedule(),
        ];
    }

    /**
     * Every researcher: which identifiers they carry, what their last fetch
     * said, and how many candidates wait on them.
     *
     * @return array<string, mixed>
     */
    private function researchers(): array
    {
        $collection = (string) ($this->config['researchers']['collection'] ?? 'people');
        $fallback = $this->fallbackAddress();

        $waitingByResearcher = [];

        foreach ($this->candidates()->get() as $candidate) {
            foreach (\array_map('strval', (array) $candidate->get('researchers', [])) as $id) {
                $waitingByResearcher[$id] = ($waitingByResearcher[$id] ?? 0) + 1;
            }
        }

        $rows = [];
        $withoutIdentifiers = 0;
        $nobodyToTell = 0;

        foreach (Entry::query()->where('collection', $collection)->orderBy('title')->get() as $researcher) {
            $id = (string) $researcher->id();
            $identifiers = $this->fields->readAll($researcher->data()->all());
            $lastRun = $this->lastRun($researcher);
            $verifier = $this->verifierName($researcher);
            $waiting = $waitingByResearcher[$id] ?? 0;

            if ($identifiers === []) {
                $withoutIdentifiers++;
            }

            if ($waiting > 0 && $verifier === null && $fallback === null) {
                $nobodyToTell++;
            }

            $rows[] = [
                'id' => $id,
                'title' => (string) $researcher->get('title', $id),
                'edit_url' => (string) $researcher->editUrl(),
                'identifiers' => $identifiers,
                'last_run' => $lastRun,
                'waiting' => $waiting,
                'verifier' => $verifier,
            ];
        }

        return [
            'collection' => $collection,
            'total' => \count($rows),
            'without_identifiers' => $withoutIdentifiers,
            'never_fetched' => \count(\array_filter($rows, static fn (array $r): bool => $r['last_run'] === null)),
            'partial' => \count(\array_filter($rows, static fn (array $r): bool => $r['last_run'] !== null && ! $r['last_run']['complete'])),
            'nobody_to_tell' => $nobodyToTell,
            'rows' => $rows,
        ];
    }

    /**
     * Which identifier kinds this site records at all. A kind with no field
     * is why a source can never run here, and that is said next to the
     * source rather than left for somebody to work out.
     *
     * @return array<string, string|null>  kind => field handle, or null
     */
    private function identifiers(): array
    {
        $map = [];

        foreach (ResearcherFieldMap::KINDS as $kind) {
            $map[$kind] = $this->fields->handleFor($kind);
        }

        return $map;
    }

    /**
     * Every registered source, and every source the config names that no
     * code answers to. The second list exists because `scopus` and
     * `web_of_science` ship in the config file as placeholders, and a site
     * that turned one on would otherwise wait for a fetch that never comes.
     *
     * @return array<string, mixed>
     */
    private function sources(): array
    {
        $configured = (array) ($this->config['sources'] ?? []);

        $registered = [];

        foreach ($this->sources->all() as $source) {
            $key = $source->sourceKey();
            $registered[] = [
                'key' => $key,
                'enabled' => (bool) (($configured[$key]['enabled'] ?? true)),
                'configured' => $source->isConfigured(),
                'needs' => $this->needs($source),
            ];
        }

        $known = \array_map(static fn (array $s): string => $s['key'], $registered);
        $unregistered = [];

        foreach ($configured as $key => $settings) {
            if (\is_string($key) && ! \in_array($key, $known, true)) {
                $unregistered[] = ['key' => $key, 'enabled' => (bool) (\is_array($settings) ? ($settings['enabled'] ?? false) : false)];
            }
        }

        return ['registered' => $registered, 'unregistered' => $unregistered];
    }

    /**
     * Why a shipped source would report itself unconfigured, in the words of
     * the config file. A host's own source gets no guess.
     */
    private function needs(PublicationSource $source): ?string
    {
        $kind = match ($source->sourceKey()) {
            'orcid' => 'orcid',
            'pubmed' => 'pubmed_author',
            default => null,
        };

        if ($kind === null || $source->isConfigured()) {
            return null;
        }

        if (! (bool) (($this->config['sources'][$source->sourceKey()]['enabled'] ?? true))) {
            return 'disabled in config';
        }

        if ($this->fields->handleFor($kind) === null) {
            return \sprintf('no researcher field is mapped for %s (researchers.fields.%s)', $kind, $kind);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function ncbi(): array
    {
        $ncbi = (array) ($this->config['ncbi'] ?? []);
        $email = \is_string($ncbi['email'] ?? null) ? \trim($ncbi['email']) : '';

        return [
            'tool' => (string) ($ncbi['tool'] ?? 'statamic-scholarly'),
            'email' => $email === '' ? null : $email,
            'api_key' => \is_string($ncbi['api_key'] ?? null) && \trim($ncbi['api_key']) !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $candidates = $this->candidates()->get();

        return [
            'collection' => $this->publications->handle(),
            'waiting' => ($this->waiting)(),
            'deferred' => $candidates->filter(static fn (EntryContract $e): bool => $e->get('deferred_at') !== null)->count(),
            'set_aside' => Entry::query()
                ->where('collection', $this->publications->handle())
                ->where('verification_status', 'candidate')
                ->whereNotNull('discarded_at')
                ->count(),
            'approved' => Entry::query()->where('collection', $this->publications->handle())->where('verification_status', 'approved')->count(),
            'auto_approved' => Entry::query()->where('collection', $this->publications->handle())->where('verification_status', 'approved')->where('auto_approved', true)->count(),
            'rejected' => Entry::query()->where('collection', $this->publications->handle())->where('verification_status', 'rejected')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scoring(): array
    {
        $scoring = (array) ($this->config['scoring'] ?? []);
        $model = $this->models->load();
        $threshold = $scoring['auto_approve_threshold'] ?? null;
        $floor = $scoring['discard_floor'] ?? null;

        return [
            'calibrated' => $model !== null,
            'fitted_at' => \is_string($model?->meta['fitted_at'] ?? null) ? $model->meta['fitted_at'] : null,
            'positives' => \is_int($model?->meta['positives'] ?? null) ? $model->meta['positives'] : null,
            'negatives' => \is_int($model?->meta['negatives'] ?? null) ? $model->meta['negatives'] : null,
            'auto_approve' => (bool) ($scoring['auto_approve'] ?? false),
            'threshold' => \is_numeric($threshold) ? (float) $threshold : null,
            'floor' => \is_numeric($floor) && (float) $floor > 0 ? (float) $floor : null,
            'min_labels' => (int) ($scoring['min_labels'] ?? 30),
            'min_labels_per_class' => (int) ($scoring['min_labels_per_class'] ?? 10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notifications(): array
    {
        $n = (array) ($this->config['notifications'] ?? []);
        $channels = \array_values(\array_filter((array) ($n['channels'] ?? ['mail']), 'is_string'));
        $webhook = \is_string($n['webhook_url'] ?? null) && \trim($n['webhook_url']) !== '';

        return [
            'channels' => $channels,
            'digest' => (bool) ($n['digest'] ?? true),
            'fallback' => $this->fallbackAddress(),
            'webhook' => $webhook,
            // The same rule NotifyVerifiers applies: "webhook" with no URL
            // is dropped, not sent nowhere.
            'webhook_dropped' => ! $webhook && \in_array('webhook', $channels, true),
            'webhook_signed' => $webhook && \is_string($n['webhook_secret'] ?? null) && \trim($n['webhook_secret']) !== '',
        ];
    }

    private function schedule(): ?string
    {
        $frequency = $this->config['schedule'] ?? null;

        return \is_string($frequency) && \trim($frequency) !== '' ? \trim($frequency) : null;
    }

    private function fallbackAddress(): ?string
    {
        $fallback = $this->config['notifications']['fallback'] ?? null;

        return \is_string($fallback) && \trim($fallback) !== '' ? \trim($fallback) : null;
    }

    private function candidates(): QueryBuilder
    {
        return Entry::query()
            ->where('collection', $this->publications->handle())
            ->where('verification_status', 'candidate')
            ->whereNull('discarded_at');
    }

    /**
     * @return array{at: string|null, complete: bool, summary: string}|null
     */
    private function lastRun(EntryContract $researcher): ?array
    {
        $run = $researcher->get(FetchForResearcher::LAST_RUN_FIELD);

        if (! \is_array($run) || ! \is_string($run['summary'] ?? null)) {
            return null;
        }

        return [
            'at' => \is_string($run['at'] ?? null) ? $run['at'] : null,
            'complete' => (bool) ($run['complete'] ?? false),
            'summary' => $run['summary'],
        ];
    }

    private function verifierName(EntryContract $researcher): ?string
    {
        $verifier = $researcher->get(NotifyVerifiers::VERIFIER_FIELD);

        if (\is_array($verifier)) {
            $verifier = $verifier[0] ?? null;
        }

        if (! \is_string($verifier) || $verifier === '') {
            return null;
        }

        $user = User::find($verifier);

        return $user === null ? null : (string) ($user->name() ?: $user->email());
    }
}
