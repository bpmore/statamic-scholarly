<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use Bpmore\Scholarly\Rules\IdentifierFormat;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads author affiliations, author ORCID iDs and the abstract out of an
 * `efetch.fcgi` XML response.
 *
 * E-summary has none of those, and they are what disambiguation runs on:
 * an affiliation matching the site's patterns, or an ORCID iD on the
 * author line, is the evidence that a name match is this researcher.
 *
 * Framework free, pinned against a recorded response. Author names are
 * rendered in E-summary's form ("Carberry J") so the two can be lined up.
 */
final class EfetchArticles
{
    /**
     * @param  list<string>  $pmids  The PMIDs asked for; each is a key in the result
     * @return array<string, array{abstract: string|null, authors: list<array{name: string, affiliation: string|null, orcid: string|null}>}|null>
     *                                                                                                                       Null when the set carries no article for that PMID
     *
     * @throws UnexpectedNcbiResponse when the body is not a PubmedArticleSet
     */
    public static function parse(string $xml, array $pmids): array
    {
        $document = new DOMDocument;
        $previous = \libxml_use_internal_errors(true);

        try {
            $loaded = \trim($xml) !== '' && $document->loadXML($xml, LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->documentElement?->tagName !== 'PubmedArticleSet') {
            throw UnexpectedNcbiResponse::missing('a PubmedArticleSet', 'efetch');
        }

        $xpath = new DOMXPath($document);
        $found = [];

        foreach ($xpath->query('/PubmedArticleSet/PubmedArticle') ?: [] as $article) {
            if (! $article instanceof DOMElement) {
                continue;
            }

            $pmid = \trim((string) $xpath->evaluate('string(MedlineCitation/PMID)', $article));

            if ($pmid === '') {
                continue;
            }

            $found[$pmid] = [
                'abstract' => self::abstract($xpath, $article),
                'authors' => self::authors($xpath, $article),
            ];
        }

        $out = [];

        foreach ($pmids as $pmid) {
            // Absent from the set is how efetch says "no such record"; there
            // is no error element per id as esummary has.
            $out[$pmid] = $found[$pmid] ?? null;
        }

        return $out;
    }

    /**
     * Structured abstracts carry a label per section; kept as "LABEL: text"
     * so the sections read in order.
     */
    private static function abstract(DOMXPath $xpath, DOMElement $article): ?string
    {
        $parts = [];

        foreach ($xpath->query('MedlineCitation/Article/Abstract/AbstractText', $article) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = \trim(\preg_replace('/\s+/u', ' ', $node->textContent) ?? $node->textContent);

            if ($text === '') {
                continue;
            }

            $label = \trim($node->getAttribute('Label'));
            $parts[] = $label === '' ? $text : $label.': '.$text;
        }

        return $parts === [] ? null : \implode("\n\n", $parts);
    }

    /**
     * @return list<array{name: string, affiliation: string|null, orcid: string|null}>
     */
    private static function authors(DOMXPath $xpath, DOMElement $article): array
    {
        $authors = [];

        foreach ($xpath->query('MedlineCitation/Article/AuthorList/Author', $article) ?: [] as $author) {
            if (! $author instanceof DOMElement) {
                continue;
            }

            $last = \trim((string) $xpath->evaluate('string(LastName)', $author));
            $initials = \trim((string) $xpath->evaluate('string(Initials)', $author));
            $collective = \trim((string) $xpath->evaluate('string(CollectiveName)', $author));

            $name = $last !== '' ? \trim($last.' '.$initials) : $collective;

            if ($name === '') {
                continue;
            }

            $affiliations = [];

            foreach ($xpath->query('AffiliationInfo/Affiliation', $author) ?: [] as $node) {
                $text = \trim(\preg_replace('/\s+/u', ' ', $node->textContent) ?? $node->textContent);

                if ($text !== '' && ! \in_array($text, $affiliations, true)) {
                    $affiliations[] = $text;
                }
            }

            $authors[] = [
                'name' => $name,
                'affiliation' => $affiliations === [] ? null : \implode('; ', $affiliations),
                'orcid' => self::orcid((string) $xpath->evaluate('string(Identifier[@Source="ORCID"])', $author)),
            ];
        }

        return $authors;
    }

    /**
     * PubMed records an ORCID iD as a bare id, a URL, or occasionally with
     * a typo. Only a well formed id is kept; a wrong one is worse than none.
     */
    private static function orcid(string $raw): ?string
    {
        $raw = \trim($raw);

        if ($raw === '') {
            return null;
        }

        if (\preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/', \strtoupper($raw), $m) !== 1) {
            return null;
        }

        return IdentifierFormat::isOrcid($m[1]) ? $m[1] : null;
    }
}
