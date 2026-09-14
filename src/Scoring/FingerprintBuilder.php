<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

use Bpmore\Scholarly\Publications\PublicationsCollection;
use Bpmore\Scholarly\Researchers\ResearcherFieldMap;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Builds a researcher's fingerprint from the entries that are certainly
 * theirs: approved ones, and candidates that arrived as an ORCID claim or
 * carry the researcher's own iD on an author line.
 *
 * A rejected entry contributes nothing. A candidate that is merely a name
 * match contributes nothing either, or the fingerprint would learn a
 * namesake's co-authors and then score the namesake's next paper up.
 */
final class FingerprintBuilder
{
    public function __construct(
        private readonly PublicationsCollection $collection,
        private readonly ResearcherFieldMap $fields,
    ) {}

    public function build(EntryContract $researcher): Fingerprint
    {
        $values = $researcher->data()->all();
        $orcid = $this->fields->read('orcid', $values);

        $names = \array_values(\array_filter([
            $researcher->get('title'),
            $this->fields->read('pubmed_author', $values),
        ], 'is_string'));

        $fingerprint = Fingerprint::start($names, $orcid);
        $id = (string) $researcher->id();

        $entries = Entry::query()
            ->where('collection', $this->collection->handle())
            ->whereIn('verification_status', ['approved', 'candidate'])
            ->get()
            ->filter(fn (EntryContract $e): bool => \in_array($id, \array_map('strval', (array) $e->get('researchers', [])), true));

        foreach ($entries as $entry) {
            if (! self::isCertain($entry, $orcid)) {
                continue;
            }

            $fingerprint = $fingerprint->withPaper(
                self::authorsOf($entry),
                \is_string($entry->get('journal')) ? $entry->get('journal') : null,
            );
        }

        return $fingerprint;
    }

    public static function isCertain(EntryContract $entry, ?string $orcid): bool
    {
        if ($entry->get('verification_status') === 'approved') {
            return true;
        }

        if ($entry->get('verification_status') !== 'candidate') {
            return false;
        }

        if (\in_array('orcid', \array_map('strval', (array) $entry->get('source_apis', [])), true)) {
            return true;
        }

        if ($orcid === null) {
            return false;
        }

        foreach (self::authorsOf($entry) as $author) {
            if (($author['orcid'] ?? null) === $orcid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{name: string, affiliation: string|null, orcid: string|null}>
     */
    public static function authorsOf(EntryContract $entry): array
    {
        $authors = [];

        foreach ((array) $entry->get('authors', []) as $author) {
            if (! \is_array($author) || ! \is_string($author['name'] ?? null)) {
                continue;
            }

            $authors[] = [
                'name' => $author['name'],
                'affiliation' => \is_string($author['affiliation'] ?? null) ? $author['affiliation'] : null,
                'orcid' => \is_string($author['orcid'] ?? null) ? $author['orcid'] : null,
            ];
        }

        return $authors;
    }
}
