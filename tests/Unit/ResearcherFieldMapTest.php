<?php

declare(strict_types=1);

use Bpmore\Scholarly\Researchers\ResearcherFieldMap;

/*
 * The most valuable tests in the suite. Every one uses handles that differ from
 * the shipped defaults, because an addon that only works against its own
 * defaults works on exactly one site and fails silently everywhere else.
 */

it('reads an identifier from whatever field the host calls it', function (): void {
    $map = ResearcherFieldMap::fromConfig(['orcid' => 'author_orcid']);

    expect($map->read('orcid', ['author_orcid' => '0000-0002-1825-0097']))
        ->toBe('0000-0002-1825-0097');
});

it('returns null for a kind the host does not map at all', function (): void {
    $map = ResearcherFieldMap::fromConfig(['orcid' => 'author_orcid']);

    expect($map->read('scopus_author', ['scopus_author_id' => '1234567890']))->toBeNull();
    expect($map->handleFor('scopus_author'))->toBeNull();
});

it('treats a blank field as absent rather than as a value', function (): void {
    $map = ResearcherFieldMap::fromConfig(['orcid' => 'author_orcid']);

    expect($map->read('orcid', ['author_orcid' => '   ']))->toBeNull();
});

it('finds only the kinds the host actually mapped', function (): void {
    $map = ResearcherFieldMap::fromConfig(['orcid' => 'a', 'pubmed_author' => 'b']);

    $found = $map->readAll(['a' => '0000-0002-1825-0097', 'b' => 'mccoy lm', 'c' => 'ignored']);

    expect(array_keys($found))->toBe(['orcid', 'pubmed_author']);
});
