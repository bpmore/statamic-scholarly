<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Publications;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Entries\QueryBuilder;
use Statamic\Facades\Entry;

/**
 * The one query every public surface uses: a researcher's APPROVED
 * publications. The tag, the feed and anything later go through here, so
 * there is exactly one place that could let a candidate out, and it
 * cannot be asked to.
 */
final class ApprovedPublications
{
    public function __construct(
        private readonly PublicationsCollection $collection,
        private readonly string $researcherCollection,
    ) {}

    /**
     * An id, or a slug in the researcher collection, to the researcher's
     * id. Null when there is no publicly published researcher.
     */
    public function resolveResearcherId(string $given): ?string
    {
        $given = \trim($given);

        if ($given === '') {
            return null;
        }

        $entry = Entry::find($given);

        if ($entry !== null && $entry->collectionHandle() === $this->researcherCollection) {
            return $entry->status() === 'published' ? (string) $entry->id() : null;
        }

        $bySlug = Entry::query()
            ->where('collection', $this->researcherCollection)
            ->whereStatus('published')
            ->where('slug', $given)
            ->first();

        return $bySlug === null ? null : (string) $bySlug->id();
    }

    /**
     * @param  string|null  $year  `2020` or `2020:2024`; ignored when malformed
     */
    public function query(string $researcherId, ?string $year = null, ?string $journal = null): QueryBuilder
    {
        $query = Entry::query()
            ->where('collection', $this->collection->handle())
            // Approval records whose work this is, not whether the site has
            // published it. Drafts and scheduled entries must stay private.
            ->whereStatus('published')
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

        $this->applyYear($query, $year);

        if ($journal !== null && \trim($journal) !== '') {
            $query->where('journal', \trim($journal));
        }

        return $query;
    }

    /**
     * `publication_date` is stored as an ISO prefix ("2020", "2020-03",
     * "2020-03-24"), so a year window is a string comparison: at or after
     * "2020" and before "2025" covers every precision in between.
     */
    private function applyYear(QueryBuilder $query, ?string $year): void
    {
        if ($year === null || \trim($year) === '') {
            return;
        }

        $parts = \explode(':', $year, 2);
        $from = \trim($parts[0]);
        $to = \trim($parts[1] ?? $parts[0]);

        if (! \ctype_digit($from) || ! \ctype_digit($to) || \strlen($from) !== 4 || \strlen($to) !== 4) {
            return;
        }

        $query->where('publication_date', '>=', $from)
            ->where('publication_date', '<', (string) ((int) $to + 1));
    }

    /**
     * The fields a public surface shows, as plain values.
     *
     * @return array<string, mixed>
     */
    public static function record(EntryContract $entry): array
    {
        $authors = [];

        foreach ((array) $entry->get('authors', []) as $author) {
            if (\is_array($author) && \is_string($author['name'] ?? null)) {
                $authors[] = [
                    'name' => $author['name'],
                    'affiliation' => \is_string($author['affiliation'] ?? null) ? $author['affiliation'] : null,
                    'orcid' => \is_string($author['orcid'] ?? null) ? $author['orcid'] : null,
                ];
            }
        }

        $string = static fn (string $key): ?string => \is_string($entry->get($key)) && \trim($entry->get($key)) !== '' ? \trim($entry->get($key)) : null;

        return [
            'id' => (string) $entry->id(),
            'title' => $string('title'),
            'journal' => $string('journal'),
            'publication_date' => $string('publication_date'),
            'volume' => $string('volume'),
            'issue' => $string('issue'),
            'pages' => $string('pages'),
            'doi' => $string('doi'),
            'pmid' => $string('pmid'),
            'abstract' => $string('abstract'),
            'authors' => $authors,
            'external_urls' => \array_values(\array_filter((array) $entry->get('external_urls', []), 'is_string')),
            'source_apis' => \array_values(\array_filter((array) $entry->get('source_apis', []), 'is_string')),
        ];
    }
}
