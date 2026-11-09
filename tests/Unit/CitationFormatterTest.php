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
        ->and(Formatter::format($two, 'apa', true))->toBe('Berry, C., &amp; Carberry, J. (2020). A Study. <i>J Things</i>, 1(2), 3-4. https://doi.org/10.1000/x')
        ->and(Formatter::format($many, 'apa'))->toContain('Author19, A., ... Author22, A. (2020).');
});

it('escapes every part of the record in HTML mode and none of it in plain text', function (string $style, int $tags): void {
    // The record is what a source returned, and an ORCID claim can be
    // approved without a person reading it. Only the title and journal
    // were escaped once; a contributor name, the pages or the DOI carrying
    // markup went into the host's page as markup.
    $record = citationRecord([
        'authors' => [['name' => '<b>Evil</b> X'], ['name' => 'Ampersand & Sons, J']],
        'publication_date' => '20<0',
        'volume' => '<v>',
        'issue' => '"7"',
        'pages' => '1-2<script>alert(1)</script>',
        'doi' => '10.1000/x"><img src=x onerror=alert(1)>',
    ]);

    $html = Formatter::format($record, $style, true);

    // Vancouver sets the journal in plain type; AMA and APA italicise it,
    // and that <i> is the only markup an HTML citation may contain.
    expect($html)->toContain($tags === 0 ? 'J Things.' : '<i>J Things</i>')
        ->toContain('&lt;b&gt;Evil&lt;/b&gt;')
        ->toContain('Ampersand &amp; Sons')
        ->toContain('20&lt;0')
        ->toContain('&lt;v&gt;')
        ->toContain('(&quot;7&quot;)')
        ->toContain('1-2&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('10.1000/x&quot;&gt;&lt;img src=x onerror=alert(1)&gt;')
        ->and(str_contains($html, '<script'))->toBeFalse('a script tag reached the HTML citation')
        ->and(str_contains($html, '<img'))->toBeFalse('an img tag reached the HTML citation')
        ->and(str_contains($html, '&amp;amp;'))->toBeFalse('a name was escaped twice')
        ->and(preg_match_all('/<[^>]*>/', $html))->toBe($tags, 'markup other than the <i> around the journal reached the HTML citation');

    $plain = Formatter::format($record, $style);

    expect($plain)->toContain('<b>Evil</b>')
        ->toContain('Ampersand & Sons')
        ->toContain('1-2<script>alert(1)</script>')
        ->toContain('10.1000/x"><img src=x onerror=alert(1)>')
        ->and(str_contains($plain, '&lt;'))->toBeFalse('plain text mode was escaped')
        ->and(str_contains($plain, '<i>'))->toBeFalse('plain text mode wrapped the journal');
})->with(['ama' => ['ama', 2], 'apa' => ['apa', 2], 'vancouver' => ['vancouver', 0]]);

it('keeps an initial outside ASCII whole when escaping', function (): void {
    // Initials are composed before the author is escaped. Split per byte,
    // "É" became two invalid bytes, and htmlspecialchars answers invalid
    // UTF-8 with an empty string: the whole author disappeared.
    $record = citationRecord(['authors' => [['name' => 'Émile Zola']]]);

    expect(Formatter::format($record, 'apa', true))->toStartWith('Zola, É. (2020).')
        ->and(Formatter::format($record, 'apa'))->toStartWith('Zola, É. (2020).');
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
