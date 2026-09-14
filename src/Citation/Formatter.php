<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Citation;

/**
 * Renders one publication record as a citation in AMA, APA or Vancouver
 * style.
 *
 * Framework free: it takes the plain record ApprovedPublications::record()
 * produces and returns a string. What it will not do is guess: a name whose
 * form it cannot tell is rendered as written, a title's case is left as
 * the source had it, and a field that is missing is left out rather than
 * filled with "n.d." or "[no journal]". A citation with an invented part is
 * a wrong citation of a real person's work.
 */
final class Formatter
{
    public const STYLES = ['ama', 'apa', 'vancouver'];

    /**
     * @param  array<string, mixed>  $record
     * @param  bool  $html  Wrap the journal in <i> and escape everything; otherwise plain text
     */
    public static function format(array $record, string $style, bool $html = false): string
    {
        $style = \strtolower(\trim($style));

        if (! \in_array($style, self::STYLES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown citation style "%s". Use one of: %s.', $style, \implode(', ', self::STYLES)));
        }

        $names = self::names($record['authors'] ?? []);
        $title = self::text($record['title'] ?? null);
        $journal = self::text($record['journal'] ?? null);
        $date = self::text($record['publication_date'] ?? null);
        $year = $date === null ? null : \substr($date, 0, 4);
        $volume = self::text($record['volume'] ?? null);
        $issue = self::text($record['issue'] ?? null);
        $pages = self::text($record['pages'] ?? null);
        $doi = self::text($record['doi'] ?? null);

        $e = $html ? static fn (?string $v): ?string => $v === null ? null : \htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : static fn (?string $v): ?string => $v;
        $i = $html ? static fn (string $v): string => '<i>'.$v.'</i>' : static fn (string $v): string => $v;

        return match ($style) {
            'ama' => self::ama($names, $e($title), $journal === null ? null : $i($e($journal)), $year, $volume, $issue, $pages, $doi),
            'vancouver' => self::vancouver($names, $e($title), $e($journal), $date, $volume, $issue, $pages, $doi),
            'apa' => self::apa($names, $e($title), $journal === null ? null : $i($e($journal)), $year, $volume, $issue, $pages, $doi, $html),
        };
    }

    /**
     * AMA 11th: up to six authors listed; more than six, the first three
     * and "et al". "Surname II. Title. Journal. Year;Vol(Issue):Pages.
     * doi:..."
     *
     * @param  list<array{surname: string, initials: string}>  $names
     */
    private static function ama(array $names, ?string $title, ?string $journal, ?string $year, ?string $volume, ?string $issue, ?string $pages, ?string $doi): string
    {
        $authors = \array_map(static fn (array $n): string => \trim($n['surname'].' '.$n['initials']), $names);

        if (\count($authors) > 6) {
            $authors = [...\array_slice($authors, 0, 3), 'et al'];
        }

        $parts = [];

        if ($authors !== []) {
            $parts[] = \implode(', ', $authors).'.';
        }

        if ($title !== null) {
            $parts[] = self::stop($title);
        }

        if ($journal !== null) {
            $parts[] = $journal.'.';
        }

        $where = self::yearVolumeIssuePages($year, $volume, $issue, $pages, ';');

        if ($where !== null) {
            $parts[] = $where.'.';
        }

        if ($doi !== null) {
            $parts[] = 'doi:'.$doi;
        }

        return \implode(' ', $parts);
    }

    /**
     * Vancouver (ICMJE): up to six authors, then "et al." "Surname II.
     * Title. Journal. Year Mon Day;Vol(Issue):Pages."
     *
     * @param  list<array{surname: string, initials: string}>  $names
     */
    private static function vancouver(array $names, ?string $title, ?string $journal, ?string $date, ?string $volume, ?string $issue, ?string $pages, ?string $doi): string
    {
        $authors = \array_map(static fn (array $n): string => \trim($n['surname'].' '.$n['initials']), $names);

        if (\count($authors) > 6) {
            $authors = [...\array_slice($authors, 0, 6), 'et al.'];
        }

        $parts = [];

        if ($authors !== []) {
            $parts[] = \rtrim(\implode(', ', $authors), '.').'.';
        }

        if ($title !== null) {
            $parts[] = self::stop($title);
        }

        if ($journal !== null) {
            $parts[] = $journal.'.';
        }

        $where = self::yearVolumeIssuePages(self::vancouverDate($date), $volume, $issue, $pages, ';');

        if ($where !== null) {
            $parts[] = $where.'.';
        }

        if ($doi !== null) {
            $parts[] = 'doi: '.$doi;
        }

        return \implode(' ', $parts);
    }

    /**
     * APA 7th: "Surname, I. I., Surname, I., & Surname, I. (Year). Title.
     * Journal, Vol(Issue), Pages. https://doi.org/..." Up to twenty
     * authors; more, the first nineteen, an ellipsis, and the last.
     *
     * @param  list<array{surname: string, initials: string}>  $names
     */
    private static function apa(array $names, ?string $title, ?string $journal, ?string $year, ?string $volume, ?string $issue, ?string $pages, ?string $doi, bool $html): string
    {
        $authors = \array_map(static fn (array $n): string => $n['initials'] === '' ? $n['surname'] : $n['surname'].', '.\implode(' ', \array_map(static fn (string $c): string => $c.'.', \str_split($n['initials']))), $names);

        if (\count($authors) > 20) {
            $joined = \implode(', ', \array_slice($authors, 0, 19)).', ... '.$authors[\count($authors) - 1];
        } elseif (\count($authors) > 1) {
            $joined = \implode(', ', \array_slice($authors, 0, -1)).', & '.$authors[\count($authors) - 1];
        } else {
            $joined = $authors[0] ?? '';
        }

        if ($html) {
            $joined = \str_replace('&', '&amp;', $joined);
        }

        $parts = [];

        if ($joined !== '') {
            $parts[] = \rtrim($joined, '.').'.';
        }

        if ($year !== null) {
            $parts[] = '('.$year.').';
        }

        if ($title !== null) {
            $parts[] = self::stop($title);
        }

        $source = [];

        if ($journal !== null) {
            $source[] = $journal;
        }

        if ($volume !== null) {
            $source[] = $volume.($issue !== null ? '('.$issue.')' : '');
        } elseif ($issue !== null) {
            $source[] = '('.$issue.')';
        }

        if ($pages !== null) {
            $source[] = $pages;
        }

        if ($source !== []) {
            $parts[] = \implode(', ', $source).'.';
        }

        if ($doi !== null) {
            $parts[] = 'https://doi.org/'.$doi;
        }

        return \implode(' ', $parts);
    }

    private static function yearVolumeIssuePages(?string $when, ?string $volume, ?string $issue, ?string $pages, string $separator): ?string
    {
        $out = $when ?? '';

        if ($volume !== null || $issue !== null) {
            $out .= ($out === '' ? '' : $separator).($volume ?? '').($issue !== null ? '('.$issue.')' : '');
        }

        if ($pages !== null) {
            $out .= ($out === '' ? '' : ':').$pages;
        }

        return $out === '' ? null : $out;
    }

    /**
     * "2025-08-12" as Vancouver writes it: "2025 Aug 12"; "2025-08" as
     * "2025 Aug"; "2025" as "2025".
     */
    private static function vancouverDate(?string $date): ?string
    {
        if ($date === null || \preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $date, $m) !== 1) {
            return $date;
        }

        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $out = $m[1];

        if (isset($m[2]) && isset($months[(int) $m[2] - 1])) {
            $out .= ' '.$months[(int) $m[2] - 1];

            if (isset($m[3])) {
                $out .= ' '.\ltrim($m[3], '0');
            }
        }

        return $out;
    }

    /**
     * Surname and initials from each stored name, in the name's own case.
     *
     * Three forms are told apart: "Surname, Given", PubMed's "Surname II",
     * and "Given Surname". Particles stay with the surname. A single word is
     * a surname with no initials. Anything else is rendered as written.
     *
     * @param  mixed  $authors
     * @return list<array{surname: string, initials: string}>
     */
    public static function names(mixed $authors): array
    {
        $out = [];

        foreach (\is_iterable($authors) ? $authors : [] as $author) {
            $raw = \is_array($author) ? ($author['name'] ?? null) : (\is_string($author) ? $author : null);
            $raw = \is_string($raw) ? \trim(\preg_replace('/\s+/u', ' ', $raw) ?? $raw) : '';

            if ($raw === '') {
                continue;
            }

            if (\str_contains($raw, ',')) {
                [$surname, $given] = \array_map('trim', \explode(',', $raw, 2));
                $out[] = ['surname' => $surname, 'initials' => self::initials($given)];

                continue;
            }

            $parts = \explode(' ', $raw);
            $last = $parts[\count($parts) - 1];

            if (\count($parts) === 1) {
                $out[] = ['surname' => $raw, 'initials' => ''];
            } elseif (\preg_match('/^[A-Z]{1,3}$/', $last) === 1) {
                $out[] = ['surname' => \implode(' ', \array_slice($parts, 0, -1)), 'initials' => $last];
            } else {
                $start = \count($parts) - 1;

                while ($start > 1 && \in_array(\mb_strtolower($parts[$start - 1]), ['de', 'van', 'der', 'den', 'von', 'da', 'di', 'del', 'della', 'le', 'la', 'du', 'dos', 'das', 'al', 'el', 'bin', 'ibn'], true)) {
                    $start--;
                }

                $out[] = [
                    'surname' => \implode(' ', \array_slice($parts, $start)),
                    'initials' => self::initials(\implode(' ', \array_slice($parts, 0, $start))),
                ];
            }
        }

        return $out;
    }

    private static function initials(string $given): string
    {
        $initials = '';

        foreach (\preg_split('/[\s.\-]+/u', \trim($given)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            // "JS" is initials already; "Josiah" gives "J".
            $initials .= \preg_match('/^[A-Z]{2,3}$/', $token) === 1 ? $token : \mb_strtoupper(\mb_substr($token, 0, 1));
        }

        return $initials;
    }

    private static function stop(string $title): string
    {
        return \preg_match('/[.?!]$/', $title) === 1 ? $title : $title.'.';
    }

    private static function text(mixed $value): ?string
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
