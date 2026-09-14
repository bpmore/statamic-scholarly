<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use Bpmore\Scholarly\Contracts\PublicationMetadataFetcher;
use Bpmore\Scholarly\Contracts\PublicationSource;
use Bpmore\Scholarly\Fetching\FetchOutcome;
use Bpmore\Scholarly\Fetching\Reason;
use Bpmore\Scholarly\Fetching\SharedRateLimit;
use Bpmore\Scholarly\Fetching\SourceFailure;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Bpmore\Scholarly\Rules\IdentifierFormat;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Statamic\Contracts\Entries\Entry;
use Throwable;

/**
 * Publications whose author list matches a researcher's PubMed author string.
 *
 * This is the source whose answer is an ESTIMATE, and the reason the review
 * queue exists. "Carberry J" is not an identifier: it is every J. Carberry
 * PubMed has ever indexed, and the addon has no way to tell them apart here.
 * Nothing from this source is anyone's work until a person says so.
 *
 * Two requests per researcher, both identified and both paced through the
 * site-wide NCBI limiter: an esearch for PMIDs (paged, because the default
 * page is twenty), then summaries through the metadata seam so a host with
 * its own PubMed client keeps one cache.
 *
 * Never runs on a render path. Every caller is a queued job or a command.
 */
final class PubmedSource implements PublicationSource
{
    private readonly SharedRateLimit $limiter;

    public function __construct(
        private readonly ResearcherFieldMap $fields,
        private readonly EutilsRequest $request,
        private readonly PublicationMetadataFetcher $metadata,
        private readonly bool $enabled = true,
        private readonly int $timeoutSeconds = 10,
    ) {
        $this->limiter = new SharedRateLimit(EsummaryFetcher::LIMITER_KEY, $request->ratePerSecond());
    }

    public function sourceKey(): string
    {
        return SummaryCandidate::SOURCE_KEY;
    }

    /**
     * Enabled, and the host has said which field holds the author string.
     *
     * The contact email is NOT checked here. A missing one is a refusal to
     * run, reported loudly by fetchForResearcher(), not a quiet skip: the
     * difference between "this site chose not to use PubMed" and "this site
     * forgot to identify itself to NCBI" is the difference between silence
     * and a fix.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->fields->handleFor('pubmed_author') !== null;
    }

    public function fetchForResearcher(Entry $researcher, ?string $since = null): FetchOutcome
    {
        $outcome = new FetchOutcome;

        if (! $this->enabled) {
            return $outcome->withFailure($this->failure(Reason::NotConfigured, 'source disabled in config'));
        }

        $handle = $this->fields->handleFor('pubmed_author');

        if ($handle === null) {
            return $outcome->withFailure($this->failure(
                Reason::NotConfigured,
                'no field is mapped to "pubmed_author" in statamic-scholarly.researchers.fields',
            ));
        }

        $author = $this->fields->read('pubmed_author', $researcher->data()->all());

        if ($author === null) {
            return $outcome->withFailure($this->failure(
                Reason::NotConfigured,
                \sprintf('field "%s" is empty on this researcher', $handle),
            ));
        }

        if (! IdentifierFormat::isPubmedAuthorString($author)) {
            return $outcome->withFailure($this->failure(
                Reason::RefusedLocally,
                \sprintf('field "%s" holds "%s", which is not a PubMed author search string', $handle, $author),
            ));
        }

        $modifiedSince = null;

        if ($since !== null) {
            try {
                $modifiedSince = new DateTimeImmutable($since);
            } catch (Throwable) {
                return $outcome->withFailure($this->failure(
                    Reason::RefusedLocally,
                    \sprintf('"%s" is not a date this source can filter by', $since),
                ));
            }
        }

        $query = new PubmedSearchQuery($author, $modifiedSince);

        try {
            [$pmids, $prevalence] = $this->search($query);
            $summaries = $pmids === [] ? [] : $this->metadata->summariesForPmids($pmids);
        } catch (MissingContactEmail $e) {
            return $outcome->withFailure($this->failure(Reason::RefusedLocally, $e->getMessage()));
        } catch (NcbiUnavailable $e) {
            return $outcome->withFailure($this->failure(Reason::TransportFailed, $e->getMessage()));
        } catch (UnexpectedNcbiResponse $e) {
            return $outcome->withFailure($this->failure(Reason::UnexpectedResponse, $e->getMessage()));
        }

        // How many PubMed records the author string matches, across all
        // time, is how common the name is: the scoring model's prior. A
        // since window narrows the ids fetched, not this count.
        if ($prevalence !== null) {
            $outcome = $outcome->withNote(self::NAME_PREVALENCE_NOTE, $prevalence);
        }

        $missing = [];
        $candidates = [];

        foreach ($pmids as $pmid) {
            $row = $summaries[$pmid] ?? null;

            if ($row === null) {
                $missing[] = $pmid;

                continue;
            }

            $candidates[$pmid] = SummaryCandidate::fromRow($row);
        }

        // Affiliations, author ORCID iDs and the abstract come from a second
        // endpoint. If it cannot answer, the candidates still go out with
        // what the summary had, and the run is partial: the last-fetched
        // stamp does not advance, so the next run asks again.
        $enrichment = null;

        try {
            $details = $candidates === [] ? [] : $this->metadata->detailsForPmids(\array_map('strval', \array_keys($candidates)));

            foreach ($candidates as $pmid => $candidate) {
                $detail = $details[$pmid] ?? null;

                if ($detail !== null) {
                    $candidates[$pmid] = $candidate->withDetails($detail['authors'], $detail['abstract']);
                }
            }
        } catch (NcbiUnavailable $e) {
            $enrichment = $this->failure(Reason::TransportFailed, 'details for '.\count($candidates).' PMID(s) could not be fetched: '.$e->getMessage());
        } catch (UnexpectedNcbiResponse $e) {
            $enrichment = $this->failure(Reason::UnexpectedResponse, 'details for '.\count($candidates).' PMID(s) could not be read: '.$e->getMessage());
        }

        $outcome = $outcome->withCandidates(...\array_values($candidates));

        // A PMID the search returned and the summary endpoint disowns is not
        // a paper that does not exist. It is two NCBI endpoints disagreeing,
        // and the run is reported as partial rather than as an answer.
        if ($missing !== []) {
            $outcome = $outcome->withFailure($this->failure(
                Reason::UnexpectedResponse,
                \sprintf('%d of %d PMIDs returned by search had no summary: %s', \count($missing), \count($pmids), \implode(', ', $missing)),
            ));
        }

        if ($enrichment !== null) {
            $outcome = $outcome->withFailure($enrichment);
        }

        return $outcome->isComplete() ? $outcome->withSourceRun($this->sourceKey()) : $outcome;
    }

    public const NAME_PREVALENCE_NOTE = 'name_prevalence';

    /**
     * Every PMID matching the query, across every page, and how many records
     * the author string matches with no date window (null when the window
     * was the whole query and the count is the same thing).
     *
     * @return array{0: list<string>, 1: int|null}
     *
     * @throws MissingContactEmail
     * @throws NcbiUnavailable
     * @throws UnexpectedNcbiResponse
     */
    private function search(PubmedSearchQuery $query): array
    {
        $pmids = [];
        $retstart = 0;
        $prevalence = null;

        do {
            // Identification is merged before the limiter is touched, so a
            // site with no contact email refuses without spending a slot.
            $params = $this->request->query($query->params($retstart));

            $this->limiter->waitForSlot();

            try {
                $response = Http::timeout($this->timeoutSeconds)->get(EutilsRequest::BASE.'esearch.fcgi', $params);
            } catch (ConnectionException $e) {
                throw new NcbiUnavailable($e->getMessage(), previous: $e);
            }

            if (! $response->successful()) {
                throw NcbiUnavailable::status($response->status(), 'esearch');
            }

            $decoded = $response->json();

            if (! \is_array($decoded)) {
                throw UnexpectedNcbiResponse::missing('a JSON body', 'esearch');
            }

            $page = EsearchResult::fromDecoded($decoded);

            // Our own offset, not the page's echo of it. A server (or a
            // test double) that ignores retstart would otherwise hand back
            // page one forever, and a queue worker would loop until killed.
            $new = \array_diff($page->ids, $pmids);

            if ($page->ids !== [] && $new === []) {
                throw new UnexpectedNcbiResponse(\sprintf('NCBI esearch repeated a page at offset %d', $retstart));
            }

            $pmids = [...$pmids, ...\array_values($new)];
            $retstart += \count($page->ids);
        } while ($page->ids !== [] && $retstart < $page->count);

        if (! $query->isWindowed()) {
            $prevalence = $page->count;
        } else {
            // One more request, without the window, only for the count.
            $this->limiter->waitForSlot();

            try {
                $response = Http::timeout($this->timeoutSeconds)->get(EutilsRequest::BASE.'esearch.fcgi', $this->request->query([...$query->unwindowed()->params(0), 'retmax' => 0]));
                $decoded = $response->successful() ? $response->json() : null;
                $prevalence = \is_array($decoded) ? EsearchResult::fromDecoded($decoded)->count : null;
            } catch (ConnectionException|UnexpectedNcbiResponse) {
                // The count is a prior, not an answer; a run without it is
                // still complete. It is simply not updated this time.
                $prevalence = null;
            }
        }

        return [\array_values(\array_unique($pmids)), $prevalence];
    }

    private function failure(Reason $reason, string $detail): SourceFailure
    {
        return new SourceFailure($this->sourceKey(), $reason, $detail);
    }
}
