<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Tags;

use Bpmore\Scholarly\Publications\ApprovedPublications;
use Statamic\Contracts\Entries\QueryBuilder;
use Statamic\Tags\Concerns\GetsQueryResults;
use Statamic\Tags\Concerns\OutputsItems;
use Statamic\Tags\Tags;

/**
 * `{{ scholarly:publications }}`: a researcher's APPROVED publications.
 *
 * The one rule that cannot be parameterised away: only approved entries come
 * out. There is no parameter to include candidates, because a candidate on a
 * public page is the addon presenting an estimate as a claim. A template that
 * needs candidates is a control-panel concern, not a frontend one.
 *
 * Parameters: `researcher` (an id, or a slug in the researcher collection;
 * defaults to the current entry), `sort` (default `publication_date:desc`),
 * `limit`, `paginate`, `as`, `year` (`2020` or `2020:2024`), `journal`.
 *
 * Nothing here fetches. It reads stored entries, as every render path must.
 */
final class Scholarly extends Tags
{
    use GetsQueryResults;
    use OutputsItems;

    protected $defaultAsKey = 'publications';

    public function __construct(private readonly ApprovedPublications $approved) {}

    /**
     * {{ scholarly:publications researcher="..." }} ... {{ /scholarly:publications }}
     */
    public function publications(): mixed
    {
        $query = $this->query();

        if ($query === null) {
            return $this->output(collect());
        }

        $this->applySort($query);

        return $this->output($this->results($query));
    }

    /**
     * {{ scholarly:feed_url researcher="..." }}: where the JSON feed for this
     * researcher lives, or nothing when there is no such researcher.
     */
    public function feedUrl(): string
    {
        $id = $this->researcherId();

        return $id === null ? '' : \route('statamic.statamic-scholarly.feed', ['researcher' => $id]);
    }

    /**
     * {{ scholarly:publications_count researcher="..." }}
     */
    public function publicationsCount(): int
    {
        return $this->query()?->count() ?? 0;
    }

    /**
     * Null when the researcher cannot be resolved. A template pointing at a
     * missing researcher renders an empty list, which is the honest output:
     * there is no approved publication to show for someone who is not there.
     */
    private function query(): ?QueryBuilder
    {
        $researcherId = $this->researcherId();

        if ($researcherId === null) {
            return null;
        }

        $year = $this->params->get('year');
        $journal = $this->params->get('journal');

        return $this->approved->query(
            $researcherId,
            \is_scalar($year) ? (string) $year : null,
            \is_scalar($journal) ? (string) $journal : null,
        );
    }

    private function researcherId(): ?string
    {
        $given = $this->params->get('researcher');

        if ($given === null || $given === '') {
            $current = $this->context->get('id');

            // Ids are strings on the Stache and may be integers on the
            // Eloquent driver; the stored researchers list holds strings.
            return \is_string($current) || \is_int($current) ? ((string) $current ?: null) : null;
        }

        return $this->approved->resolveResearcherId((string) $given);
    }

    private function applySort(QueryBuilder $query): void
    {
        $sort = (string) $this->params->get('sort', 'publication_date:desc');

        foreach (\explode('|', $sort) as $clause) {
            [$field, $direction] = \array_pad(\explode(':', \trim($clause), 2), 2, 'asc');

            if ($field === '') {
                continue;
            }

            $query->orderBy($field, \strtolower($direction) === 'desc' ? 'desc' : 'asc');
        }
    }
}
