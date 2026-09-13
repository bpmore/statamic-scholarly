<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Publications;

/**
 * Collapses candidates that are the same paper.
 *
 * Framework free, because this is the logic most likely to be wrong in a way
 * that looks right. Two copies of one paper render as two entries and somebody
 * notices. Two different papers merged into one render as a single plausible
 * entry and nobody does.
 *
 * Three keys, tried in order, and only the first two ever merge:
 *
 *   1. DOI, normalised. Covers works PubMed never indexes.
 *   2. PMID, normalised.
 *   3. Title plus year, which NEVER merges. It records a pair for a human to
 *      look at, because titles legitimately collide across errata, reprints,
 *      corrections and conference abstracts of the same study.
 *
 * A candidate carrying both a DOI and a PMID joins the group of either, which
 * is how a DOI-only source and a PMID-only source end up on one paper.
 */
final class Deduplicator
{
    /**
     * @param  list<CandidatePublication>  $candidates
     * @return list<MergedPublication>
     */
    public function merge(array $candidates): array
    {
        /** @var array<int, list<CandidatePublication>> $groups */
        $groups = [];

        /** @var array<string, int> $keyToGroup */
        $keyToGroup = [];

        $nextGroup = 0;

        foreach ($candidates as $candidate) {
            $keys = $candidate->hardKeys();

            if ($keys === []) {
                // No hard identifier: it gets a group of its own. Title
                // matching below may flag it for review, but nothing merges it.
                $groups[$nextGroup] = [$candidate];
                $nextGroup++;

                continue;
            }

            // Every group this candidate's keys already point at. More than one
            // means this candidate is the evidence that those groups are the
            // same paper, so they get united.
            $hit = [];

            foreach ($keys as $key) {
                if (isset($keyToGroup[$key])) {
                    $hit[$keyToGroup[$key]] = true;
                }
            }

            $hitGroups = \array_keys($hit);

            if ($hitGroups === []) {
                $group = $nextGroup;
                $nextGroup++;
                $groups[$group] = [$candidate];
            } else {
                $group = $hitGroups[0];
                $groups[$group][] = $candidate;

                // Fold every other matched group into the first, and repoint
                // their keys. This is what makes the join transitive.
                foreach (\array_slice($hitGroups, 1) as $absorbed) {
                    foreach ($groups[$absorbed] as $moved) {
                        $groups[$group][] = $moved;
                    }

                    unset($groups[$absorbed]);

                    foreach ($keyToGroup as $k => $g) {
                        if ($g === $absorbed) {
                            $keyToGroup[$k] = $group;
                        }
                    }
                }
            }

            foreach ($keys as $key) {
                $keyToGroup[$key] = $group;
            }
        }

        $merged = [];

        foreach ($groups as $members) {
            $merged[] = $this->build($members);
        }

        return $this->attachTitleMatches($merged);
    }

    /**
     * @param  list<CandidatePublication>  $members
     */
    private function build(array $members): MergedPublication
    {
        $doi = null;
        $pmid = null;
        $sources = [];

        foreach ($members as $member) {
            $doi ??= PublicationKey::normaliseDoi($member->doi);
            $pmid ??= PublicationKey::normalisePmid($member->pmid);

            if (! \in_array($member->sourceKey, $sources, true)) {
                $sources[] = $member->sourceKey;
            }
        }

        return new MergedPublication($members, $sources, $doi, $pmid);
    }

    /**
     * Flag merged publications whose titles match but whose identifiers did not.
     *
     * These are NOT merged. The pair is recorded so a verifier can decide,
     * because this is exactly where an automatic merge would destroy data: an
     * erratum and the paper it corrects share a title and are different works.
     *
     * @param  list<MergedPublication>  $merged
     * @return list<MergedPublication>
     */
    private function attachTitleMatches(array $merged): array
    {
        /** @var array<string, list<int>> $byTitle */
        $byTitle = [];

        foreach ($merged as $index => $publication) {
            $title = PublicationKey::normaliseTitle($publication->title());

            if ($title === null) {
                continue;
            }

            $byTitle[$title][] = $index;
        }

        foreach ($byTitle as $indexes) {
            if (\count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                $pairs = [];

                foreach ($indexes as $other) {
                    if ($other === $index) {
                        continue;
                    }

                    $pairs[] = [$merged[$index]->candidates[0], $merged[$other]->candidates[0]];
                }

                $merged[$index] = new MergedPublication(
                    $merged[$index]->candidates,
                    $merged[$index]->sourceKeys,
                    $merged[$index]->doi,
                    $merged[$index]->pmid,
                    $pairs,
                );
            }
        }

        return \array_values($merged);
    }
}
