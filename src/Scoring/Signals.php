<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Scoring;

use Bpmore\Scholarly\Publications\CandidatePublication;

/**
 * Everything the model is allowed to look at for one candidate, as plain
 * numbers, with the reason each one is there.
 *
 * Computed once, stored with the score as the breakdown, so a verifier can
 * see WHY a candidate scored as it did. A score with no breakdown is a
 * number a person has to take on faith, and the whole point is that they
 * should not have to.
 */
final readonly class Signals
{
    /**
     * @param  bool  $anchored  An author line carries the researcher's own ORCID iD, or the researcher claimed the work on their ORCID record. Certain.
     * @param  NameMatch  $nameMatch  How well the best-matching author line matches the researcher's name
     * @param  bool  $affiliationMatch  That author line's affiliation matches one of the site's patterns or one seen on a certain paper
     * @param  float  $coauthorOverlap  Fraction of the candidate's other authors who appear on the researcher's certain papers, 0 to 1
     * @param  int  $sharedCoauthors  The count behind that fraction
     * @param  bool  $journalSeen  The journal appears on a certain paper
     * @param  int  $corroboration  How many sources reported the paper
     * @param  int|null  $namePrevalence  How many PubMed records the researcher's author string matches, when known
     */
    public function __construct(
        public bool $anchored,
        public NameMatch $nameMatch,
        public bool $affiliationMatch,
        public float $coauthorOverlap,
        public int $sharedCoauthors,
        public bool $journalSeen,
        public int $corroboration,
        public ?int $namePrevalence,
    ) {}

    /**
     * @param  list<string>  $affiliationPatterns  From config; ships empty
     */
    public static function compute(CandidatePublication $candidate, Fingerprint $fingerprint, array $affiliationPatterns, ?int $namePrevalence, int $corroboration = 1): self
    {
        $anchored = $candidate->sourceKey === 'orcid';
        $bestMatch = NameMatch::None;
        $selfLine = null;
        $others = [];

        foreach ($candidate->authors as $author) {
            $name = AuthorName::parse($author['name'] ?? '');

            if ($name === null) {
                continue;
            }

            $orcid = $author['orcid'] ?? null;

            if ($orcid !== null && $fingerprint->orcid !== null && $orcid === $fingerprint->orcid) {
                $anchored = true;
                $selfLine = $author;
                $bestMatch = NameMatch::Full;

                continue;
            }

            $match = $fingerprint->nameMatch($name);

            if ($match !== NameMatch::None && ($selfLine === null || ($match === NameMatch::Full && $bestMatch !== NameMatch::Full))) {
                // Keep the first best-matching line as the researcher; the
                // rest of the list are co-authors.
                if ($selfLine !== null) {
                    $others[] = $selfLine;
                }

                $selfLine = $author;
                $bestMatch = $match;

                continue;
            }

            $others[] = $author;
        }

        $affiliation = \mb_strtolower(\trim((string) ($selfLine['affiliation'] ?? '')));
        $affiliationMatch = $affiliation !== '' && self::affiliationMatches($affiliation, $affiliationPatterns, $fingerprint->affiliations);

        $shared = 0;
        $counted = 0;

        foreach ($others as $other) {
            $name = AuthorName::parse($other['name'] ?? '');

            if ($name === null) {
                continue;
            }

            $counted++;

            if (isset($fingerprint->coauthors[Fingerprint::key($name)])) {
                $shared++;
            }
        }

        $journal = \mb_strtolower(\trim((string) $candidate->journal));

        return new self(
            anchored: $anchored,
            nameMatch: $bestMatch,
            affiliationMatch: $affiliationMatch,
            coauthorOverlap: $counted === 0 ? 0.0 : $shared / $counted,
            sharedCoauthors: $shared,
            journalSeen: $journal !== '' && isset($fingerprint->journals[$journal]),
            corroboration: \max(1, $corroboration),
            namePrevalence: $namePrevalence,
        );
    }

    /**
     * @param  list<string>  $patterns
     * @param  list<string>  $seen
     */
    private static function affiliationMatches(string $affiliation, array $patterns, array $seen): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = \mb_strtolower(\trim($pattern));

            if ($pattern !== '' && \str_contains($affiliation, $pattern)) {
                return true;
            }
        }

        foreach ($seen as $known) {
            if ($known !== '' && ($affiliation === $known || \str_contains($affiliation, $known) || \str_contains($known, $affiliation))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'anchored' => $this->anchored,
            'name_match' => $this->nameMatch->value,
            'affiliation_match' => $this->affiliationMatch,
            'coauthor_overlap' => \round($this->coauthorOverlap, 3),
            'shared_coauthors' => $this->sharedCoauthors,
            'journal_seen' => $this->journalSeen,
            'corroboration' => $this->corroboration,
            'name_prevalence' => $this->namePrevalence,
        ];
    }
}
