<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

use Bpmore\Scholarly\Publications\CandidatePublication;

/**
 * Turns one `esummary.fcgi` document summary into a candidate.
 *
 * Framework free, pinned against recorded NCBI rows. E-summary carries no
 * affiliations and no abstract (those are in E-fetch's XML), so authors here
 * are names only. That is the one field a later scoring pass will want more
 * of, and it is left empty rather than guessed.
 */
final class SummaryCandidate
{
    public const SOURCE_KEY = 'pubmed';

    private const MONTHS = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
        'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
    ];

    /**
     * @param  array<string, mixed>  $row  One entry of `result` keyed by PMID
     */
    public static function fromRow(array $row): CandidatePublication
    {
        $pmid = self::string($row['uid'] ?? null);

        return new CandidatePublication(
            sourceKey: self::SOURCE_KEY,
            title: self::string($row['title'] ?? null),
            doi: self::doi($row),
            pmid: $pmid,
            journal: self::string($row['fulljournalname'] ?? null) ?? self::string($row['source'] ?? null),
            publicationDate: self::date(self::string($row['pubdate'] ?? null)),
            authors: self::authors($row['authors'] ?? null),
            externalUrls: $pmid === null ? [] : ['https://pubmed.ncbi.nlm.nih.gov/'.$pmid.'/'],
            volume: self::string($row['volume'] ?? null),
            issue: self::string($row['issue'] ?? null),
            pages: self::string($row['pages'] ?? null),
        );
    }

    /**
     * The DOI from `articleids`, falling back to `elocationid`.
     *
     * Older records carry the DOI only in `elocationid` as "doi: 10.x/y", and
     * a candidate without it cannot join the same paper arriving from ORCID.
     *
     * @param  array<string, mixed>  $row
     */
    private static function doi(array $row): ?string
    {
        foreach ($row['articleids'] ?? [] as $id) {
            if (\is_array($id) && ($id['idtype'] ?? null) === 'doi') {
                $value = self::string($id['value'] ?? null);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        $eloc = self::string($row['elocationid'] ?? null);

        if ($eloc !== null && \preg_match('/doi:\s*(10\.\S+)/i', $eloc, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * PubMed's `pubdate` at the precision it has: "2020 Mar 24" becomes
     * 2020-03-24, "2019 Nov-Dec" becomes 2019-11, "2020 Spring" becomes 2020.
     *
     * `sortpubdate` is not used: it pads a year-only date to January the 1st,
     * which is a day the record never claimed.
     */
    private static function date(?string $pubdate): ?string
    {
        if ($pubdate === null || \preg_match('/^(\d{4})(?:\s+([A-Za-z]{3}))?(?:\s+(\d{1,2}))?/', $pubdate, $m) !== 1) {
            return null;
        }

        $month = self::MONTHS[\strtolower($m[2] ?? '')] ?? null;

        if ($month === null) {
            return $m[1];
        }

        $day = $m[3] ?? null;

        return $day === null
            ? $m[1].'-'.$month
            : $m[1].'-'.$month.'-'.\str_pad($day, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<array{name: string}>
     */
    private static function authors(mixed $authors): array
    {
        $out = [];

        foreach (\is_array($authors) ? $authors : [] as $author) {
            $name = \is_array($author) ? self::string($author['name'] ?? null) : null;

            if ($name !== null) {
                $out[] = ['name' => $name];
            }
        }

        return $out;
    }

    private static function string(mixed $value): ?string
    {
        if (\is_int($value)) {
            $value = (string) $value;
        }

        if (! \is_string($value)) {
            return null;
        }

        $value = \trim($value);

        return $value === '' ? null : $value;
    }
}
