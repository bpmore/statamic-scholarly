<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Import;

use Bpmore\Scholarly\Publications\CandidatePublication;

/**
 * Reads a BibTeX file into candidates.
 *
 * Handles what exports actually contain: `@type{key, field = {value}}`,
 * quoted values, bare numbers and month macros, nested braces in titles,
 * `and`-separated authors in "Surname, Given" or "Given Surname" form, and
 * a DOI written as a URL. It is not a full BibTeX implementation: no
 * `@string` macros, no `#` concatenation, no cross-references. An export
 * from Google Scholar, Zotero, Mendeley or a reference manager uses none
 * of those.
 *
 * Framework free, pinned against a recorded export.
 */
final class BibtexParser
{
    public const SOURCE_KEY = 'import';

    /**
     * @return array{candidates: list<CandidatePublication>, skipped: list<string>}
     *                                                                            Skipped entries by key, with the reason
     */
    public static function parse(string $bibtex): array
    {
        $candidates = [];
        $skipped = [];
        $offset = 0;

        while (($at = \strpos($bibtex, '@', $offset)) !== false) {
            $open = \strpos($bibtex, '{', $at);

            if ($open === false) {
                break;
            }

            $type = \strtolower(\trim(\substr($bibtex, $at + 1, $open - $at - 1)));
            $close = self::matchingBrace($bibtex, $open);

            if ($close === null) {
                $skipped[] = 'entry at byte '.$at.': unbalanced braces';

                break;
            }

            $body = \substr($bibtex, $open + 1, $close - $open - 1);
            $offset = $close + 1;

            if (\in_array($type, ['comment', 'preamble', 'string'], true)) {
                continue;
            }

            [$key, $fields] = self::entry($body);
            $candidate = self::candidate($fields);

            if ($candidate === null) {
                $skipped[] = ($key === '' ? 'entry without a key' : $key).': no title and no identifier';

                continue;
            }

            $candidates[] = $candidate;
        }

        return ['candidates' => $candidates, 'skipped' => $skipped];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function entry(string $body): array
    {
        $comma = \strpos($body, ',');
        $key = \trim($comma === false ? $body : \substr($body, 0, $comma));
        $rest = $comma === false ? '' : \substr($body, $comma + 1);
        $fields = [];
        $pos = 0;
        $length = \strlen($rest);

        while ($pos < $length) {
            $eq = \strpos($rest, '=', $pos);

            if ($eq === false) {
                break;
            }

            $name = \strtolower(\trim(\substr($rest, $pos, $eq - $pos), " \t\n\r,"));
            $pos = $eq + 1;

            while ($pos < $length && \ctype_space($rest[$pos])) {
                $pos++;
            }

            if ($pos >= $length) {
                break;
            }

            if ($rest[$pos] === '{') {
                $end = self::matchingBrace($rest, $pos);

                if ($end === null) {
                    break;
                }

                $value = \substr($rest, $pos + 1, $end - $pos - 1);
                $pos = $end + 1;
            } elseif ($rest[$pos] === '"') {
                $end = \strpos($rest, '"', $pos + 1);

                if ($end === false) {
                    break;
                }

                $value = \substr($rest, $pos + 1, $end - $pos - 1);
                $pos = $end + 1;
            } else {
                // A bare number or a month macro: runs to the next comma.
                $end = \strpos($rest, ',', $pos);
                $value = \trim(\substr($rest, $pos, $end === false ? null : $end - $pos));
                $pos = $end === false ? $length : $end;
            }

            if ($name !== '') {
                $fields[$name] = self::clean($value);
            }

            $next = \strpos($rest, ',', $pos);
            $pos = $next === false ? $length : $next + 1;
        }

        return [$key, $fields];
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function candidate(array $fields): ?CandidatePublication
    {
        $title = self::text($fields['title'] ?? null);
        $doi = self::doi($fields['doi'] ?? null);
        $pmid = self::text($fields['pmid'] ?? $fields['pubmed'] ?? $fields['pmid_id'] ?? null);

        if ($title === null && $doi === null && $pmid === null) {
            return null;
        }

        $urls = [];

        foreach (['url', 'link'] as $field) {
            $url = self::text($fields[$field] ?? null);

            if ($url !== null && \preg_match('#^https?://#i', $url) === 1) {
                $urls[] = $url;
            }
        }

        return new CandidatePublication(
            sourceKey: self::SOURCE_KEY,
            title: $title,
            doi: $doi,
            pmid: $pmid,
            journal: self::text($fields['journal'] ?? $fields['booktitle'] ?? $fields['publisher'] ?? null),
            publicationDate: self::date($fields['year'] ?? null, $fields['month'] ?? null),
            abstract: self::text($fields['abstract'] ?? null),
            authors: self::authors($fields['author'] ?? null),
            externalUrls: $urls,
        );
    }

    /**
     * "Surname, Given and Given Surname" to a list of names, given first.
     *
     * @return list<array{name: string}>
     */
    public static function authors(?string $raw): array
    {
        if ($raw === null || \trim($raw) === '') {
            return [];
        }

        $authors = [];

        foreach (\preg_split('/\s+and\s+/i', \trim($raw)) ?: [] as $part) {
            $part = \trim($part);

            if ($part === '' || \strtolower($part) === 'others') {
                continue;
            }

            if (\str_contains($part, ',')) {
                [$surname, $given] = \array_map('trim', \explode(',', $part, 2));
                $part = \trim($given.' '.$surname);
            }

            $authors[] = ['name' => \preg_replace('/\s+/', ' ', $part) ?? $part];
        }

        return $authors;
    }

    private static function doi(?string $raw): ?string
    {
        $raw = self::text($raw);

        if ($raw === null) {
            return null;
        }

        return \preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:)\s*#i', '', $raw) ?? $raw;
    }

    private static function date(?string $year, ?string $month): ?string
    {
        $year = self::text($year);

        if ($year === null || \preg_match('/(\d{4})/', $year, $m) !== 1) {
            return null;
        }

        $months = ['jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'];
        $month = \strtolower(\substr(self::text($month) ?? '', 0, 3));

        if (isset($months[$month])) {
            return $m[1].'-'.$months[$month];
        }

        if (\ctype_digit($month) && (int) $month >= 1 && (int) $month <= 12) {
            return $m[1].'-'.\str_pad($month, 2, '0', STR_PAD_LEFT);
        }

        return $m[1];
    }

    /**
     * Strips the braces authors use to protect capitals, and the common
     * TeX escapes, so "A {Methodology} for \& Co" reads as it should.
     */
    private static function clean(string $value): string
    {
        $value = \preg_replace('/\\\\([&%$#_{}])/', '$1', $value) ?? $value;
        $value = \str_replace(['{', '}'], '', $value);
        $value = \str_replace('--', '-', $value);

        return \trim(\preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = \trim($value);

        return $value === '' ? null : $value;
    }

    private static function matchingBrace(string $s, int $open): ?int
    {
        $depth = 0;
        $length = \strlen($s);

        for ($i = $open; $i < $length; $i++) {
            if ($s[$i] === '\\') {
                $i++;

                continue;
            }

            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
