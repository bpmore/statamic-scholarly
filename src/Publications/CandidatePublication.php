<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Publications;

/**
 * One publication as a source reported it, before de-duplication or scoring.
 *
 * A value object rather than an entry: nothing here has been written anywhere,
 * and several of these usually turn out to be the same paper.
 */
final readonly class CandidatePublication
{
    /**
     * @param  list<array{name: string, affiliation?: string|null, orcid?: string|null}>  $authors
     * @param  list<string>  $externalUrls
     * @param  list<string>  $sourceRecordIds  Stable record URLs, scoped to the source and researcher
     */
    public function __construct(
        public string $sourceKey,
        public ?string $title = null,
        public ?string $doi = null,
        public string|int|null $pmid = null,
        public ?string $journal = null,
        public ?string $publicationDate = null,
        public ?string $abstract = null,
        public array $authors = [],
        public array $externalUrls = [],
        public ?string $volume = null,
        public ?string $issue = null,
        public ?string $pages = null,
        public array $sourceRecordIds = [],
    ) {}

    /**
     * The same candidate with the fuller author list and abstract a second
     * request supplied. Bibliographic fields and identifiers are untouched.
     *
     * @param  list<array{name: string, affiliation?: string|null, orcid?: string|null}>  $authors
     */
    public function withDetails(array $authors, ?string $abstract): self
    {
        return new self(
            $this->sourceKey,
            $this->title,
            $this->doi,
            $this->pmid,
            $this->journal,
            $this->publicationDate,
            $abstract ?? $this->abstract,
            $authors === [] ? $this->authors : $authors,
            $this->externalUrls,
            $this->volume,
            $this->issue,
            $this->pages,
            $this->sourceRecordIds,
        );
    }

    /**
     * The strongest identifier this candidate carries, or null when it has
     * none.
     *
     * Order matters and is not arbitrary: a DOI covers works PubMed never
     * indexes, so it is the first key tried. See Deduplicator for what happens
     * when this returns null.
     */
    public function hardKey(): ?string
    {
        $doi = PublicationKey::normaliseDoi($this->doi);

        if ($doi !== null) {
            return 'doi:'.$doi;
        }

        $pmid = PublicationKey::normalisePmid($this->pmid);

        return $pmid === null ? null : 'pmid:'.$pmid;
    }

    /**
     * Every hard key this candidate carries, so a merge can match on either.
     *
     * A candidate with both a DOI and a PMID is the join between a source that
     * reports one and a source that reports the other. Keying only on the
     * strongest would leave those two unmerged.
     *
     * @return list<string>
     */
    public function hardKeys(): array
    {
        $keys = [];

        $doi = PublicationKey::normaliseDoi($this->doi);

        if ($doi !== null) {
            $keys[] = 'doi:'.$doi;
        }

        $pmid = PublicationKey::normalisePmid($this->pmid);

        if ($pmid !== null) {
            $keys[] = 'pmid:'.$pmid;
        }

        return $keys;
    }
}
