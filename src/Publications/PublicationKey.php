<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Publications;

/**
 * Normalises the two identifiers publications are de-duplicated on.
 *
 * Framework free on purpose: this is the logic whose output looks plausible
 * when it is wrong, so it has to be testable without booting anything.
 *
 * The same paper arrives from several sources wearing different clothes. ORCID
 * reports a DOI as a full https URL, Crossref reports the bare identifier, and
 * a hand-typed one carries a "doi:" prefix and whatever case the typist used.
 * Comparing those three as strings finds three papers.
 */
final class PublicationKey
{
    /**
     * Reduce a DOI to the form two sources can be compared on.
     *
     * DOIs are case insensitive by specification, so the comparison form is
     * lower case. The registrant prefix always begins "10." and everything
     * before it is a resolver, not part of the identifier.
     *
     * Returns null rather than a best guess when the value is not a DOI: a
     * wrong key silently merges two different papers, which is worse than
     * failing to merge two copies of one.
     */
    public static function normaliseDoi(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $candidate = \trim($value);

        if ($candidate === '') {
            return null;
        }

        // Strip any resolver, with or without a scheme, then the "doi:" scheme
        // form. Done in this order because "https://doi.org/doi:10.x" exists in
        // the wild and neither pattern alone catches it.
        $candidate = \preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $candidate) ?? $candidate;
        $candidate = \preg_replace('#^doi:\s*#i', '', $candidate) ?? $candidate;
        $candidate = \trim($candidate);

        // A DOI is a "10." prefix, a registrant code, a slash, then a suffix
        // that may contain almost anything except whitespace.
        if (\preg_match('#^10\.\d{4,9}/\S+$#', $candidate) !== 1) {
            return null;
        }

        return \mb_strtolower($candidate);
    }

    /**
     * Reduce a PMID to its comparison form.
     *
     * A PMID is a positive integer with no leading zero. Values arrive as ints,
     * as strings, and occasionally prefixed "PMID:", so all three normalise to
     * the same digits.
     */
    public static function normalisePmid(string|int|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $candidate = \trim((string) $value);
        $candidate = \preg_replace('#^pmid:\s*#i', '', $candidate) ?? $candidate;
        $candidate = \trim($candidate);

        if (\preg_match('#^[1-9]\d{0,7}$#', $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }

    /**
     * Reduce a title to a form two sources can be compared on.
     *
     * Only ever used as the third de-duplication key, and only to route a pair
     * into the review queue. It never merges anything on its own: titles
     * collide across genuinely different papers (errata, reprints, and the
     * evergreen "Introduction"), so a match here is a question rather than an
     * answer.
     */
    public static function normaliseTitle(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $candidate = \mb_strtolower(\trim($value));

        // Collapse anything that is not a letter, digit or space. Sources
        // disagree about typographic quotes, hyphens and trailing full stops,
        // and none of those disagreements mean a different paper.
        $candidate = \preg_replace('#[^\p{L}\p{N}]+#u', ' ', $candidate) ?? $candidate;
        $candidate = \trim(\preg_replace('#\s+#u', ' ', $candidate) ?? $candidate);

        return $candidate === '' ? null : $candidate;
    }
}
