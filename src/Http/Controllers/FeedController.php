<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Http\Controllers;

use Bpmore\Scholarly\Publications\ApprovedPublications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * `GET /!/statamic-scholarly/feed/{researcher}`: a researcher's approved
 * publications as JSON, for another site or a widget to read.
 *
 * The same query as the tag, so it cannot show a candidate the tag would
 * not. Nothing fetches; it reads stored entries.
 */
final class FeedController
{
    public function __construct(private readonly ApprovedPublications $approved) {}

    public function __invoke(Request $request, string $researcher): JsonResponse
    {
        $id = $this->approved->resolveResearcherId($researcher);

        if ($id === null) {
            return new JsonResponse(['error' => 'No such researcher.'], 404);
        }

        $year = $request->query('year');
        $journal = $request->query('journal');
        $limit = $request->query('limit');

        $query = $this->approved->query(
            $id,
            \is_string($year) ? $year : null,
            \is_string($journal) ? $journal : null,
        )->orderBy('publication_date', 'desc');

        if (\is_string($limit) && \ctype_digit($limit) && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $entry = Entry::find($id);
        $publications = $query->get()->map(fn (EntryContract $e): array => ApprovedPublications::record($e))->values()->all();

        return new JsonResponse([
            'researcher' => [
                'id' => $id,
                'title' => $entry?->get('title'),
                'url' => $entry?->absoluteUrl(),
            ],
            // Every publication here was confirmed by a person, by the
            // researcher's own ORCID iD, or by a calibrated score, and the
            // record does not say which. A consumer that needs to know
            // reads the site, not the feed.
            'status' => 'approved',
            'count' => \count($publications),
            'generated_at' => \date(DATE_ATOM),
            'publications' => $publications,
        ], 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
