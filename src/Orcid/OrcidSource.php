<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Orcid;

use Bpmore\Scholarly\Contracts\PublicationSource;
use Bpmore\Scholarly\Fetching\FetchOutcome;
use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Fetching\SharedRateLimit;
use Bpmore\Scholarly\Fetching\SourceFailure;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Bpmore\Scholarly\Rules\IdentifierFormat;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Statamic\Contracts\Entries\Entry;
use Throwable;

/**
 * Publications a researcher has claimed on their own ORCID record.
 *
 * This is the one source whose answer is not an estimate. A work on an ORCID
 * record was put there by the researcher or by a source they authorised, so
 * a candidate from here needs no name matching. It still goes through review
 * like every other candidate: ORCID records carry mistakes too.
 *
 * Reads the public API. A token is optional and only raises the ceiling; the
 * source runs without one. Compare NCBI, where the addon refuses to run
 * unidentified, because ORCID does not ask clients to identify themselves.
 *
 * Never runs on a render path. Every caller is a queued job or a command.
 */
final class OrcidSource implements PublicationSource
{
    public const string DEFAULT_BASE_URL = 'https://pub.orcid.org/v3.0/';

    private const string LIMITER_KEY = 'scholarly:orcid';

    /**
     * Well under ORCID's published ceiling for the public API, so that other
     * ORCID clients on the same site, and the burst two queue workers make
     * together, do not tip it over.
     */
    private const int REQUESTS_PER_SECOND = 8;

    private readonly SharedRateLimit $limiter;

    public function __construct(
        private readonly ResearcherFieldMap $fields,
        private readonly bool $enabled = true,
        private readonly ?string $accessToken = null,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeoutSeconds = 10,
    ) {
        $this->limiter = new SharedRateLimit(self::LIMITER_KEY, self::REQUESTS_PER_SECOND);
    }

    public function sourceKey(): string
    {
        return OrcidWorks::SOURCE_KEY;
    }

    /**
     * Enabled, and the host has said which field holds an ORCID iD.
     *
     * Whether a particular researcher has one recorded is a per-researcher
     * question and is answered by fetchForResearcher(), as a failure, so the
     * report can name the field to fill in.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->fields->handleFor('orcid') !== null;
    }

    public function fetchForResearcher(Entry $researcher, ?string $since = null): FetchOutcome
    {
        $outcome = new FetchOutcome;

        if (! $this->enabled) {
            return $outcome->withFailure($this->failure(Reason::NotConfigured, 'source disabled in config'));
        }

        $handle = $this->fields->handleFor('orcid');

        if ($handle === null) {
            return $outcome->withFailure($this->failure(
                Reason::NotConfigured,
                'no field is mapped to "orcid" in statamic-scholarly.researchers.fields',
            ));
        }

        $orcid = $this->fields->read('orcid', $researcher->data()->all());

        if ($orcid === null) {
            return $outcome->withFailure($this->failure(
                Reason::NotConfigured,
                \sprintf('field "%s" is empty on this researcher', $handle),
            ));
        }

        // A malformed value is refused before any request. Sending it would
        // come back 404, which reads as "ORCID has never heard of them" when
        // the truth is a stray character in the field.
        if (! IdentifierFormat::isOrcid($orcid)) {
            return $outcome->withFailure($this->failure(
                Reason::RefusedLocally,
                \sprintf('field "%s" holds "%s", which is not a well formed ORCID iD', $handle, $orcid),
            ));
        }

        $sinceMillis = null;

        if ($since !== null) {
            try {
                $sinceMillis = (new DateTimeImmutable($since))->getTimestamp() * 1000;
            } catch (Throwable) {
                return $outcome->withFailure($this->failure(
                    Reason::RefusedLocally,
                    \sprintf('"%s" is not a date this source can filter by', $since),
                ));
            }
        }

        $this->limiter->waitForSlot();

        try {
            $response = $this->client()->get($this->baseUrl.$orcid.'/works');
        } catch (ConnectionException $e) {
            return $outcome->withFailure($this->failure(Reason::TransportFailed, $e->getMessage()));
        }

        if ($response->status() === 404) {
            return $outcome->withFailure($this->failure(
                Reason::UnknownIdentifier,
                \sprintf('ORCID has no record %s: check the value in field "%s"', $orcid, $handle),
            ));
        }

        if (! $response->successful()) {
            return $outcome->withFailure($this->failure(
                Reason::TransportFailed,
                \sprintf('HTTP %d from %s', $response->status(), $this->baseUrl),
            ));
        }

        $decoded = $response->json();

        if (! \is_array($decoded)) {
            return $outcome->withFailure($this->failure(Reason::UnexpectedResponse, 'body was not a JSON object'));
        }

        try {
            $candidates = OrcidWorks::candidates($decoded, $sinceMillis);
        } catch (UnexpectedOrcidResponse $e) {
            return $outcome->withFailure($this->failure(Reason::UnexpectedResponse, $e->getMessage()));
        }

        return $outcome
            ->withCandidates(...$candidates)
            ->withSourceRun($this->sourceKey());
    }

    private function client(): PendingRequest
    {
        $client = Http::timeout($this->timeoutSeconds)->acceptJson();

        if ($this->accessToken !== null && \trim($this->accessToken) !== '') {
            $client = $client->withToken(\trim($this->accessToken));
        }

        return $client;
    }

    private function failure(Reason $reason, string $detail): SourceFailure
    {
        return new SourceFailure($this->sourceKey(), $reason, $detail);
    }
}
