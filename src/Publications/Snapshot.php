<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Publications;

/**
 * The stored bibliographic fields for one merged publication.
 *
 * Framework free: takes a MergedPublication, returns the values an entry
 * stores. Bibliographic fields are stored rather than computed (see
 * DECISIONS), so this is the one place the choice of which source's title,
 * which source's journal, and which source's author list wins is made.
 *
 * The rule is first non-empty in arrival order for scalars, and the longest
 * list for authors. Sources are consulted in the order the run consulted
 * them, so ORCID (a claim) is preferred to PubMed (an estimate) when both
 * have a value. Authors are the exception because ORCID's summary carries
 * none and PubMed's carries the full list.
 */
final class Snapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromMerged(MergedPublication $merged): array
    {
        $title = null;
        $journal = null;
        $date = null;
        $abstract = null;
        $authors = [];
        $urls = [];

        foreach ($merged->candidates as $candidate) {
            $title ??= self::text($candidate->title);
            $journal ??= self::text($candidate->journal);
            $date ??= self::text($candidate->publicationDate);
            $abstract ??= self::text($candidate->abstract);

            if (\count($candidate->authors) > \count($authors)) {
                $authors = $candidate->authors;
            }

            foreach ($candidate->externalUrls as $url) {
                if (! \in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        $title ??= self::fallbackTitle($merged);

        return [
            'title' => $title,
            // Stored so a later run can find this entry by title without
            // loading the collection: the key the de-duplicator flags on.
            'title_key' => PublicationKey::normaliseTitle($title),
            'journal' => $journal,
            'publication_date' => $date,
            'abstract' => $abstract,
            'doi' => $merged->doi,
            'pmid' => $merged->pmid,
            'authors' => \array_map(static fn (array $a): array => [
                'name' => $a['name'],
                'affiliation' => $a['affiliation'] ?? null,
                'orcid' => $a['orcid'] ?? null,
            ], $authors),
            'external_urls' => $urls,
            'source_apis' => $merged->sourceKeys,
        ];
    }

    /**
     * An entry needs a title to be findable in the control panel. A paper
     * with no title from any source is labelled by its identifier, which is
     * true, rather than by a placeholder, which invites nobody to fix it.
     */
    private static function fallbackTitle(MergedPublication $merged): string
    {
        if ($merged->doi !== null) {
            return 'doi:'.$merged->doi;
        }

        if ($merged->pmid !== null) {
            return 'pmid:'.$merged->pmid;
        }

        return 'untitled';
    }

    private static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = \trim($value);

        return $value === '' ? null : $value;
    }
}
