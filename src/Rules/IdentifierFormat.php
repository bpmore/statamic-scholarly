<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Rules;

use Bpmore\Scholarly\Publications\PublicationKey;

/**
 * The identifier formats, as plain predicates.
 *
 * Separated from the Laravel rule objects so the patterns can be tested and
 * reused without a validator. These are external standards: the shapes are
 * fixed by ORCID, NCBI, Elsevier and Clarivate rather than by this addon, so
 * they change only when those bodies change them.
 */
final class IdentifierFormat
{
    /**
     * ORCID iD: four groups of four, last character may be a check digit X.
     *
     * The check digit is NOT verified here. A mistyped iD that happens to have
     * a valid checksum is rarer than a valid iD this addon would wrongly
     * reject, and the authoritative check is whether ORCID returns works for
     * it.
     */
    public static function isOrcid(string $value): bool
    {
        return \preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/D', \trim($value)) === 1;
    }

    /** PubMed identifier: a positive integer, no leading zero, up to 8 digits. */
    public static function isPmid(string $value): bool
    {
        return PublicationKey::normalisePmid($value) !== null;
    }

    /** DOI: a "10." registrant prefix, a slash, and a non-empty suffix. */
    public static function isDoi(string $value): bool
    {
        return PublicationKey::normaliseDoi($value) !== null;
    }

    /** Scopus Author ID: 10 to 12 digits, no leading zero. */
    public static function isScopusAuthorId(string $value): bool
    {
        return \preg_match('/^[1-9]\d{9,11}$/D', \trim($value)) === 1;
    }

    /** Web of Science ResearcherID: letters, a serial, and a year. */
    public static function isResearcherId(string $value): bool
    {
        return \preg_match('/^[A-Z]{1,3}-\d{4}-(19|20)\d\d$/D', \trim($value)) === 1;
    }

    /**
     * PubMed author search string, for example "mccoy lm" or "o'brien ja".
     *
     * Not an identifier at all, which is the whole reason disambiguation
     * exists. Accepted loosely on purpose: rejecting an unusual real name is a
     * worse failure than accepting a string that finds nothing.
     */
    public static function isPubmedAuthorString(string $value): bool
    {
        $candidate = \trim($value);

        if ($candidate === '') {
            return false;
        }

        return \preg_match("/^[\p{L}\-' ]+,?[\p{L}\-' ]*$/u", $candidate) === 1;
    }
}
