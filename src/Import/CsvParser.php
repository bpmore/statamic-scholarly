<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Import;

use Bpmore\Scholarly\Publications\CandidatePublication;

/**
 * Reads a CSV file into candidates, by column name.
 *
 * The header row names the columns and the names are matched loosely:
 * Google Scholar's "Authors,Title,Publication,...,Year" and a hand-kept
 * "title;doi;pmid;journal;date;authors" both work. The delimiter is
 * sniffed from the header (comma, semicolon or tab). Authors are split on
 * semicolons, or on commas when the cell has no semicolon and more than
 * one comma-separated part.
 *
 * Framework free, pinned against recorded files.
 */
final class CsvParser
{
    /** Loose header names to the field they mean. */
    private const COLUMNS = [
        'title' => ['title'],
        'doi' => ['doi'],
        'pmid' => ['pmid', 'pubmed', 'pubmed id', 'pubmed_id'],
        'journal' => ['journal', 'publication', 'source', 'venue', 'booktitle', 'container'],
        'date' => ['date', 'publication_date', 'publication date', 'year', 'published'],
        'authors' => ['authors', 'author', 'creators'],
        'abstract' => ['abstract', 'summary'],
        'url' => ['url', 'link'],
    ];

    /**
     * @return array{candidates: list<CandidatePublication>, skipped: list<string>}
     *
     * @throws \InvalidArgumentException when there is no usable header row
     */
    public static function parse(string $csv): array
    {
        $csv = \preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = \preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = \array_values(\array_filter($lines, static fn (string $l): bool => \trim($l) !== ''));

        if ($lines === []) {
            throw new \InvalidArgumentException('The file is empty.');
        }

        $delimiter = self::delimiter($lines[0]);
        $header = \array_map(static fn (string $h): string => \strtolower(\trim($h)), \str_getcsv($lines[0], $delimiter, '"', '\\'));
        $map = [];

        foreach (self::COLUMNS as $field => $names) {
            foreach ($header as $index => $name) {
                if (\in_array($name, $names, true)) {
                    $map[$field] = $index;

                    break;
                }
            }
        }

        if (! isset($map['title']) && ! isset($map['doi']) && ! isset($map['pmid'])) {
            throw new \InvalidArgumentException('The header row has no title, doi or pmid column. Found: '.\implode(', ', $header));
        }

        $candidates = [];
        $skipped = [];

        foreach (\array_slice($lines, 1) as $number => $line) {
            $row = \str_getcsv($line, $delimiter, '"', '\\');
            $cell = static function (string $field) use ($row, $map): ?string {
                $value = isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;
                $value = $value === null ? null : \trim($value);

                return $value === '' ? null : $value;
            };

            $title = $cell('title');
            $doi = $cell('doi');
            $pmid = $cell('pmid');

            if ($title === null && $doi === null && $pmid === null) {
                $skipped[] = 'row '.($number + 2).': no title, doi or pmid';

                continue;
            }

            $url = $cell('url');

            $candidates[] = new CandidatePublication(
                sourceKey: BibtexParser::SOURCE_KEY,
                title: $title,
                doi: $doi === null ? null : (\preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:)\s*#i', '', $doi) ?? $doi),
                pmid: $pmid,
                journal: $cell('journal'),
                publicationDate: self::date($cell('date')),
                abstract: $cell('abstract'),
                authors: self::authors($cell('authors')),
                externalUrls: $url !== null && \preg_match('#^https?://#i', $url) === 1 ? [$url] : [],
            );
        }

        return ['candidates' => $candidates, 'skipped' => $skipped];
    }

    private static function delimiter(string $header): string
    {
        $best = ',';
        $count = \substr_count($header, ',');

        foreach ([';', "\t"] as $candidate) {
            if (\substr_count($header, $candidate) > $count) {
                $best = $candidate;
                $count = \substr_count($header, $candidate);
            }
        }

        return $best;
    }

    /**
     * @return list<array{name: string}>
     */
    private static function authors(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (\str_contains($raw, ';')) {
            $parts = \explode(';', $raw);
        } elseif (\preg_match('/\s+and\s+/i', $raw) === 1) {
            $parts = \preg_split('/\s+and\s+/i', $raw) ?: [];
        } else {
            $parts = \explode(',', $raw);
        }

        $authors = [];

        foreach ($parts as $part) {
            $part = \trim(\preg_replace('/\s+/', ' ', $part) ?? $part);

            if ($part !== '') {
                $authors[] = ['name' => $part];
            }
        }

        return $authors;
    }

    /**
     * A year, an ISO date, or a full timestamp: kept at the precision given.
     */
    private static function date(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (\preg_match('/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?/', $raw, $m) === 1) {
            return $m[1].(isset($m[2]) ? '-'.$m[2] : '').(isset($m[3]) ? '-'.$m[3] : '');
        }

        return \preg_match('/(\d{4})/', $raw, $m) === 1 ? $m[1] : null;
    }
}
