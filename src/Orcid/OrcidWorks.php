<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Orcid;

use Bpmore\Scholarly\Publications\CandidatePublication;

/**
 * Turns a decoded `GET /v3.0/{orcid}/works` response into candidates.
 *
 * Framework free: it takes the decoded array and returns values, so the
 * rules below can be pinned against a recorded response without a network.
 *
 * ORCID groups work summaries it considers the same work (one from Crossref,
 * one the researcher typed in) under a shared set of external identifiers.
 * One group becomes one candidate here. The group's identifiers are the
 * union across its summaries, so a candidate carries the DOI even when the
 * preferred summary does not; the addon's own de-duplication then joins that
 * DOI to whatever PubMed reports.
 */
final class OrcidWorks
{
    public const SOURCE_KEY = 'orcid';

    /**
     * @param  array<string, mixed>  $decoded  The JSON-decoded works response
     * @param  int|null  $modifiedSinceMillis  Keep only groups changed at or after this instant
     * @return list<CandidatePublication>
     *
     * @throws UnexpectedOrcidResponse when the response is not a works listing
     */
    public static function candidates(array $decoded, ?int $modifiedSinceMillis = null, ?string $recordUri = null): array
    {
        $candidates = [];

        foreach (self::selected($decoded, $modifiedSinceMillis) as [$group, $summary]) {
            $ids = self::selfIdentifiers($group['external-ids'] ?? null);

            $candidates[] = new CandidatePublication(
                sourceKey: self::SOURCE_KEY,
                title: self::string($summary['title']['title']['value'] ?? null),
                doi: $ids['doi'] ?? null,
                pmid: $ids['pmid'] ?? null,
                journal: self::string($summary['journal-title']['value'] ?? null),
                publicationDate: self::date($summary['publication-date'] ?? null),
                externalUrls: \array_values(\array_filter([self::string($summary['url']['value'] ?? null)])),
                sourceRecordIds: self::recordIds($group, $recordUri),
            );
        }

        return $candidates;
    }

    /**
     * Keep every summary's identity so changing the preferred summary does
     * not create another entry. A put-code alone is not globally unique.
     *
     * @return list<string>
     */
    private static function recordIds(array $group, ?string $recordUri): array
    {
        if ($recordUri === null || \trim($recordUri) === '') {
            return [];
        }

        $ids = [];

        foreach ($group['work-summary'] ?? [] as $summary) {
            $code = $summary['put-code'] ?? null;

            if ((\is_int($code) || (\is_string($code) && \ctype_digit($code))) && (int) $code > 0) {
                $ids[] = \rtrim($recordUri, '/').'/work/'.(int) $code;
            }
        }

        return \array_values(\array_unique($ids));
    }

    /**
     * The put-code of each candidate's preferred summary, in candidates()
     * order, for fetching the full record. Null where a summary has none.
     *
     * @param  array<string, mixed>  $decoded
     * @return list<int|null>
     *
     * @throws UnexpectedOrcidResponse
     */
    public static function putCodes(array $decoded, ?int $modifiedSinceMillis = null): array
    {
        $codes = [];

        foreach (self::selected($decoded, $modifiedSinceMillis) as [$group, $summary]) {
            $code = $summary['put-code'] ?? null;
            $codes[] = \is_int($code) || (\is_string($code) && \ctype_digit($code)) ? (int) $code : null;
        }

        return $codes;
    }

    /**
     * Every group that survives the since filter, with its preferred summary.
     *
     * @param  array<string, mixed>  $decoded
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     *
     * @throws UnexpectedOrcidResponse
     */
    private static function selected(array $decoded, ?int $modifiedSinceMillis): array
    {
        // The ORCID API returns `"group": []` for a record with no works, so a
        // missing key is a different response, not an empty one.
        if (! \array_key_exists('group', $decoded) || ! \is_array($decoded['group'])) {
            throw UnexpectedOrcidResponse::missing('the "group" list');
        }

        $selected = [];

        foreach ($decoded['group'] as $group) {
            if (! \is_array($group)) {
                throw UnexpectedOrcidResponse::missing('a group object');
            }

            if ($modifiedSinceMillis !== null && self::lastModified($group) < $modifiedSinceMillis) {
                continue;
            }

            $summary = self::preferredSummary($group);

            if ($summary === null) {
                throw UnexpectedOrcidResponse::missing('a work summary in a group');
            }

            $selected[] = [$group, $summary];
        }

        return $selected;
    }

    /**
     * Milliseconds since the epoch, as ORCID reports it, or zero when absent
     * so an undated group is treated as never modified rather than as new.
     *
     * @param  array<string, mixed>  $group
     */
    private static function lastModified(array $group): int
    {
        $value = $group['last-modified-date']['value'] ?? null;

        return \is_int($value) ? $value : (\is_numeric($value) ? (int) $value : 0);
    }

    /**
     * The summary the researcher chose to display, or the first one.
     *
     * A group holds one summary per source that asserted the work. ORCID's
     * `display-index` is higher for the one the record owner prefers, and that
     * is usually the one with the cleaner title and journal.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>|null
     */
    private static function preferredSummary(array $group): ?array
    {
        $summaries = $group['work-summary'] ?? null;

        if (! \is_array($summaries) || $summaries === []) {
            return null;
        }

        $best = null;
        $bestIndex = null;

        foreach ($summaries as $summary) {
            if (! \is_array($summary)) {
                continue;
            }

            $index = (int) ($summary['display-index'] ?? 0);

            if ($best === null || $index > $bestIndex) {
                $best = $summary;
                $bestIndex = $index;
            }
        }

        return $best;
    }

    /**
     * The DOI and PMID that identify THIS work, keyed by type.
     *
     * Only identifiers whose relationship is `self` count. A `part-of` DOI is
     * the book a chapter sits in, or the journal issue, and keying a chapter
     * on it would merge every chapter of the book into one believable entry.
     * ORCID keeps `part-of` ids off the group level, but the filter stays
     * here because that is a property of their API, not of ours.
     *
     * @param  mixed  $externalIds
     * @return array<string, string>
     */
    private static function selfIdentifiers(mixed $externalIds): array
    {
        $found = [];

        foreach ($externalIds['external-id'] ?? [] as $id) {
            if (! \is_array($id)) {
                continue;
            }

            $relationship = $id['external-id-relationship'] ?? 'self';

            if ($relationship !== 'self') {
                continue;
            }

            $type = \strtolower((string) ($id['external-id-type'] ?? ''));
            $value = self::string($id['external-id-value'] ?? null);

            if ($value === null || ! \in_array($type, ['doi', 'pmid'], true)) {
                continue;
            }

            // First self identifier of a type wins. A group carrying two
            // different DOIs is ORCID's grouping being wrong, and picking the
            // first is at least deterministic.
            $found[$type] ??= $value;
        }

        return $found;
    }

    /**
     * ORCID's split date as an ISO 8601 prefix: `2012`, `2012-03` or
     * `2012-03-15`. The precision is whatever the record has; a day is never
     * invented.
     *
     * @param  mixed  $date
     */
    private static function date(mixed $date): ?string
    {
        if (! \is_array($date)) {
            return null;
        }

        $parts = [];

        foreach (['year', 'month', 'day'] as $unit) {
            $value = self::string($date[$unit]['value'] ?? null);

            if ($value === null || ! \ctype_digit($value)) {
                break;
            }

            $parts[] = $unit === 'year' ? $value : \str_pad($value, 2, '0', STR_PAD_LEFT);
        }

        return $parts === [] ? null : \implode('-', $parts);
    }

    private static function string(mixed $value): ?string
    {
        if (! \is_string($value)) {
            return null;
        }

        $value = \trim($value);

        return $value === '' ? null : $value;
    }
}
