<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use DateTimeImmutable;

/**
 * The `esearch.fcgi` parameters for one researcher's author search.
 *
 * Framework free, so the term and the date window can be asserted exactly.
 * Two things here are easy to get quietly wrong:
 *
 *   `retmax` is always sent. NCBI's default is twenty, and twenty papers with
 *   no error looks like a complete answer for a researcher with two hundred.
 *
 *   The date window filters on modification date (`mdat`), not publication
 *   date. "Since the last fetch" has to catch a 2015 paper that PubMed only
 *   attached a DOI to yesterday, and a publication-date window would not.
 */
final readonly class PubmedSearchQuery
{
    /** NCBI's ceiling for `retmax` on esearch is ten thousand; this stays well under it. */
    public const PAGE_SIZE = 500;

    public function __construct(
        private string $authorString,
        private ?DateTimeImmutable $modifiedSince = null,
    ) {}

    public function isWindowed(): bool
    {
        return $this->modifiedSince !== null;
    }

    public function unwindowed(): self
    {
        return new self($this->authorString);
    }

    /**
     * The term as PubMed's author field expects it: quoted, tagged.
     *
     * Double quotes inside the value would end the phrase early and turn the
     * remainder into free text, so they are dropped.
     */
    public function term(): string
    {
        $author = \trim(\str_replace('"', '', $this->authorString));

        return \sprintf('"%s"[Author]', $author);
    }

    /**
     * @return array<string, string|int>
     */
    public function params(int $retstart = 0): array
    {
        $params = [
            'db' => 'pubmed',
            'retmode' => 'json',
            'term' => $this->term(),
            'retmax' => self::PAGE_SIZE,
            'retstart' => $retstart,
        ];

        if ($this->modifiedSince !== null) {
            // NCBI requires both ends of the window when either is given.
            $params['datetype'] = 'mdat';
            $params['mindate'] = $this->modifiedSince->format('Y/m/d');
            $params['maxdate'] = '3000';
        }

        return $params;
    }
}
