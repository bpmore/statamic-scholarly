<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * What is known for certain about one researcher's publishing: the people
 * they have published with, where they were when they did, and the journals
 * they used. Built only from papers that are certainly theirs: ORCID-claimed
 * ones and ones a person confirmed.
 *
 * Framework free. The pipeline builds it from entries; the tests build it
 * from arrays.
 */
final readonly class Fingerprint
{
    /**
     * @param  list<AuthorName>  $selfNames  Every form the researcher's own name takes
     * @param  string|null  $orcid  The researcher's own iD, the one hard anchor
     * @param  array<string, int>  $coauthors  Surname+initials key to how many certain papers they share
     * @param  list<string>  $affiliations  Affiliation strings seen on the researcher's own author line, lower case
     * @param  array<string, int>  $journals  Journal name (lower case) to count
     * @param  int  $certainPapers  How many papers this was built from
     */
    public function __construct(
        public array $selfNames,
        public ?string $orcid,
        public array $coauthors = [],
        public array $affiliations = [],
        public array $journals = [],
        public int $certainPapers = 0,
    ) {}

    /**
     * @param  list<string>  $selfNameForms  e.g. the entry title and the PubMed author string
     */
    public static function start(array $selfNameForms, ?string $orcid): self
    {
        $names = [];

        foreach ($selfNameForms as $form) {
            $parsed = AuthorName::parse($form);

            if ($parsed !== null) {
                $names[] = $parsed;
            }
        }

        return new self($names, $orcid);
    }

    /**
     * Fold one certain paper in.
     *
     * @param  list<array{name: string, affiliation?: string|null, orcid?: string|null}>  $authors
     */
    public function withPaper(array $authors, ?string $journal): self
    {
        $coauthors = $this->coauthors;
        $affiliations = $this->affiliations;
        $journals = $this->journals;

        foreach ($authors as $author) {
            $name = AuthorName::parse($author['name'] ?? '');

            if ($name === null) {
                continue;
            }

            if ($this->isSelf($name, $author['orcid'] ?? null)) {
                $affiliation = \mb_strtolower(\trim((string) ($author['affiliation'] ?? '')));

                if ($affiliation !== '' && ! \in_array($affiliation, $affiliations, true)) {
                    $affiliations[] = $affiliation;
                }

                continue;
            }

            $key = self::key($name);
            $coauthors[$key] = ($coauthors[$key] ?? 0) + 1;
        }

        $journal = \mb_strtolower(\trim((string) $journal));

        if ($journal !== '') {
            $journals[$journal] = ($journals[$journal] ?? 0) + 1;
        }

        return new self($this->selfNames, $this->orcid, $coauthors, $affiliations, $journals, $this->certainPapers + 1);
    }

    /**
     * Whether an author line is the researcher: by iD when both sides have
     * one, by name otherwise.
     */
    public function isSelf(AuthorName $name, ?string $orcid): bool
    {
        if ($orcid !== null && $this->orcid !== null) {
            return $orcid === $this->orcid;
        }

        foreach ($this->selfNames as $self) {
            if ($self->matches($name) !== NameMatch::None) {
                return true;
            }
        }

        return false;
    }

    /**
     * The best match between an author line and the researcher's own names.
     */
    public function nameMatch(AuthorName $name): NameMatch
    {
        $best = NameMatch::None;

        foreach ($this->selfNames as $self) {
            $match = $self->matches($name);

            if ($match === NameMatch::Full) {
                return $match;
            }

            if ($match === NameMatch::Surname) {
                $best = $match;
            }
        }

        return $best;
    }

    public static function key(AuthorName $name): string
    {
        return $name->surname.'|'.\substr($name->initials, 0, 1);
    }
}
