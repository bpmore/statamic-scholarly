<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Tags;

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Statamic\Contracts\Entries\QueryBuilder;
use Statamic\Facades\Entry;
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

    public function __construct(private readonly PublicationsCollection $collection) {}

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

        $query = Entry::query()
            ->where('collection', $this->collection->handle())
            ->where('verification_status', 'approved');

        // The addon stores researcher ids as strings; a host's entries
        // field may store the same id as an integer on the Eloquent driver,
        // and JSON containment is typed. Match either form.
        $forms = \ctype_digit($researcherId) ? [$researcherId, (int) $researcherId] : [$researcherId];

        $query->where(function (QueryBuilder $q) use ($forms): void {
            foreach ($forms as $i => $form) {
                $i === 0 ? $q->whereJsonContains('researchers', $form) : $q->orWhereJsonContains('researchers', $form);
            }
        });

        $this->applyYear($query);

        if (($journal = $this->params->get('journal')) !== null && $journal !== '') {
            $query->where('journal', $journal);
        }

        return $query;
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

        $given = (string) $given;

        if (Entry::find($given) !== null) {
            return $given;
        }

        $bySlug = Entry::query()
            ->where('collection', (string) config('statamic-scholarly.researchers.collection'))
            ->where('slug', $given)
            ->first();

        return $bySlug === null ? null : (string) $bySlug->id();
    }

    /**
     * `publication_date` is stored as an ISO prefix ("2020", "2020-03",
     * "2020-03-24"), so a year window is a string comparison: at or after
     * "2020" and before "2025" covers every precision in between.
     */
    private function applyYear(QueryBuilder $query): void
    {
        $year = $this->params->get('year');

        if ($year === null || $year === '') {
            return;
        }

        $parts = \explode(':', (string) $year, 2);
        $from = \trim($parts[0]);
        $to = \trim($parts[1] ?? $parts[0]);

        if (! \ctype_digit($from) || ! \ctype_digit($to) || \strlen($from) !== 4 || \strlen($to) !== 4) {
            return;
        }

        $query->where('publication_date', '>=', $from)
            ->where('publication_date', '<', (string) ((int) $to + 1));
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
