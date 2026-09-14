<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

/**
 * The signals as a numeric vector, one number per term, the same for the
 * shipped prior and for a model fitted to this site's decisions.
 *
 * Kept in one place so the two can never disagree about what a term means:
 * a fitted weight for `coauthor_overlap` multiplies exactly the number the
 * prior's weight multiplied.
 */
final class Features
{
    /** Term names, in the order the weight vectors use. */
    public const TERMS = [
        'intercept',
        'name_full',
        'name_surname',
        'affiliation_match',
        'coauthor_overlap',
        'shared_coauthors',
        'journal_seen',
        'corroboration',
        'name_prevalence',
    ];

    /**
     * @return array<string, float> Keyed by term
     */
    public static function vector(Signals $s): array
    {
        return [
            'intercept' => 1.0,
            'name_full' => $s->nameMatch === NameMatch::Full ? 1.0 : 0.0,
            'name_surname' => $s->nameMatch === NameMatch::Surname ? 1.0 : 0.0,
            'affiliation_match' => $s->affiliationMatch ? 1.0 : 0.0,
            'coauthor_overlap' => $s->coauthorOverlap,
            // Capped: the fifth shared co-author says little the fourth did not.
            'shared_coauthors' => \min($s->sharedCoauthors, 4) / 4,
            'journal_seen' => $s->journalSeen ? 1.0 : 0.0,
            'corroboration' => (float) \max(0, $s->corroboration - 1),
            // Decades above ten records: a name matching ten records is a
            // near-identifier; one matching ten thousand is a lottery.
            'name_prevalence' => $s->namePrevalence === null || $s->namePrevalence < 10
                ? 0.0
                : \log10($s->namePrevalence / 10),
        ];
    }
}
