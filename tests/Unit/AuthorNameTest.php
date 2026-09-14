<?php

declare(strict_types=1);

use Bpmore\Scholarly\Scoring\AuthorName;
use Bpmore\Scholarly\Scoring\NameMatch;

it('reduces every form the sources use to a surname and initials', function (string $raw, string $surname, string $initials): void {
    $name = AuthorName::parse($raw);

    expect($name)->not->toBeNull()
        ->and($name->surname)->toBe($surname)
        ->and($name->initials)->toBe($initials);
})->with([
    'pubmed' => ['Carberry J', 'carberry', 'j'],
    'pubmed two initials' => ['Carberry JS', 'carberry', 'js'],
    'orcid surname first' => ['Carberry, J.S.', 'carberry', 'js'],
    'natural' => ['Josiah Carberry', 'carberry', 'j'],
    'natural with middle' => ['Josiah S. Carberry', 'carberry', 'js'],
    'pubmed particle' => ['de Vries AB', 'de vries', 'ab'],
    'natural particle' => ['Jan de Vries', 'de vries', 'j'],
    'surname first particle' => ['van der Berg, A. B.', 'van der berg', 'ab'],
    'apostrophe' => ["O'Brien JA", 'obrien', 'ja'],
    'diacritics and hyphen' => ['García-Márquez, Gabriel', 'garciamarquez', 'g'],
    'diacritic pubmed' => ['Müller K', 'muller', 'k'],
    'surname only' => ['Carberry', 'carberry', ''],
]);

it('returns null for nothing', function (): void {
    expect(AuthorName::parse('   '))->toBeNull();
});

it('says two names could be one person, never that they are', function (string $a, string $b, NameMatch $expected): void {
    expect(AuthorName::parse($a)->matches(AuthorName::parse($b)))->toBe($expected);
})->with([
    'pubmed vs natural' => ['Carberry J', 'Josiah S. Carberry', NameMatch::Full],
    'prefix of initials' => ['Carberry J', 'Carberry JS', NameMatch::Full],
    'initials disagree' => ['Carberry J', 'Carberry, S.', NameMatch::Surname],
    'no initials one side' => ['Carberry J', 'Carberry', NameMatch::Surname],
    'different surname' => ['Carberry J', 'Carbery J', NameMatch::None],
    'diacritics folded' => ['Muller K', 'Müller, Klaus', NameMatch::Full],
]);
