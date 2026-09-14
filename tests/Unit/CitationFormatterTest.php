<?php

declare(strict_types=1);

use Bpmore\Scholarly\Citation\Formatter;

function citationRecord(array $overrides = []): array
{
    return [
        'title' => 'A Study', 'journal' => 'J Things', 'publication_date' => '2020-03-04',
        'volume' => '1', 'issue' => '2', 'pages' => '3-4', 'doi' => '10.1000/x',
        'authors' => [['name' => 'Berry C']],
        ...$overrides,
    ];
}

it('tells the three name forms apart and keeps the source\'s case', function (string $raw, string $surname, string $initials): void {
    expect(Formatter::names([['name' => $raw]]))->toBe([['surname' => $surname, 'initials' => $initials]]);
})->with([
    'pubmed' => ['Carberry JS', 'Carberry', 'JS'],
    'surname first' => ['Carberry, J. S.', 'Carberry', 'JS'],
    'surname first full' => ['García-Márquez, Gabriel', 'García-Márquez', 'G'],
    'natural' => ['Josiah S. Carberry', 'Carberry', 'JS'],
    'natural particle' => ['Jan de Vries', 'de Vries', 'J'],
    'pubmed with a two-word surname' => ['GBD Collaborators X', 'GBD Collaborators', 'X'],
    'collective' => ['Collaborators', 'Collaborators', ''],
]);

it('lists up to six authors in AMA and cuts to three plus et al past that', function (): void {
    $six = citationRecord(['authors' => array_map(fn (int $i) => ['name' => 'Author'.$i.' A'], range(1, 6))]);
    $seven = citationRecord(['authors' => array_map(fn (int $i) => ['name' => 'Author'.$i.' A'], range(1, 7))]);

    expect(Formatter::format($six, 'ama'))->toStartWith('Author1 A, Author2 A, Author3 A, Author4 A, Author5 A, Author6 A. A Study.')
        ->and(Formatter::format($seven, 'ama'))->toStartWith('Author1 A, Author2 A, Author3 A, et al. A Study.')
        ->and(Formatter::format($seven, 'vancouver'))->toStartWith('Author1 A, Author2 A, Author3 A, Author4 A, Author5 A, Author6 A, et al. A Study.');
});

it('writes APA with an ampersand before the last author and an ellipsis past twenty', function (): void {
    $two = citationRecord(['authors' => [['name' => 'Berry C'], ['name' => 'Carberry J']]]);
    $many = citationRecord(['authors' => array_map(fn (int $i) => ['name' => 'Author'.$i.' A'], range(1, 22))]);

    expect(Formatter::format($two, 'apa'))->toBe('Berry, C., & Carberry, J. (2020). A Study. J Things, 1(2), 3-4. https://doi.org/10.1000/x')
        ->and(Formatter::format($many, 'apa'))->toContain('Author19, A., ... Author22, A. (2020).');
});

it('leaves out what it does not have rather than inventing it', function (): void {
    $bare = ['title' => 'Only a title', 'authors' => []];

    expect(Formatter::format($bare, 'ama'))->toBe('Only a title.')
        ->and(Formatter::format($bare, 'apa'))->toBe('Only a title.')
        ->and(Formatter::format($bare, 'vancouver'))->toBe('Only a title.')
        ->and(Formatter::format(citationRecord(['volume' => null, 'issue' => null, 'pages' => null]), 'ama'))->toBe('Berry C. A Study. J Things. 2020. doi:10.1000/x')
        ->and(Formatter::format(citationRecord(['publication_date' => '2020']), 'vancouver'))->toBe('Berry C. A Study. J Things. 2020;1(2):3-4. doi: 10.1000/x')
        ->and(Formatter::format(citationRecord(['title' => 'Ends with a question?']), 'ama'))->toContain('Ends with a question? J Things.');
});

it('refuses an unknown style', function (): void {
    expect(static fn () => Formatter::format(citationRecord(), 'chicago'))->toThrow(InvalidArgumentException::class);
});
