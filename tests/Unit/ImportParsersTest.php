<?php

declare(strict_types=1);

use Bpmore\Scholarly\Import\BibtexParser;
use Bpmore\Scholarly\Import\CsvParser;

function importFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../__fixtures__/import/'.$name);
}

it('reads a reference-manager BibTeX export, braces, escapes, month macros and DOI URLs included', function (): void {
    $parsed = BibtexParser::parse(importFixture('scholar-export.bib'));

    expect($parsed['candidates'])->toHaveCount(4)
        ->and($parsed['skipped'])->toBe(['untitled: no title and no identifier']);

    [$thin, $method, $plasmons, $talk] = $parsed['candidates'];

    expect($thin->sourceKey)->toBe('import')
        ->and($thin->title)->toBe('Developing Thin Clients Using Amphibious Epistemologies')
        ->and($thin->doi)->toBe('10.5555/12345679')
        ->and($thin->journal)->toBe('Journal of Psychoceramics')
        ->and($thin->publicationDate)->toBe('2008')
        ->and(array_column($thin->authors, 'name'))->toBe(['Josiah Carberry', 'J. J. Quinn']);

    expect($method->title)->toBe('A Methodology for the Emulation of Architecture', 'protective braces are stripped')
        ->and($method->doi)->toBe('10.5555/12345680', 'a DOI URL is reduced to the DOI')
        ->and($method->publicationDate)->toBe('2012-03', 'a month macro is read')
        ->and($method->abstract)->toBe('We describe a methodology. It has nested braces and a & sign.')
        ->and($method->externalUrls)->toBe(['https://example.org/paper']);

    expect($plasmons->pmid)->toBe('12345')
        ->and($plasmons->journal)->toBe('IEEE Transactions on Plasma Science', 'booktitle stands in for journal')
        ->and(array_column($plasmons->authors, 'name'))->toBe(['J.J. Quinn', 'J.S. Carberry']);

    expect($talk->doi)->toBeNull()
        ->and($talk->pmid)->toBeNull()
        ->and($talk->hardKey())->toBeNull('a title-only record is kept, and the de-duplicator gives it a group of its own');
});

it('stops at unbalanced braces and says where', function (): void {
    $parsed = BibtexParser::parse("@article{ok, title={Fine}, doi={10.1000/x}}\n@article{broken, title={Never closed");

    expect($parsed['candidates'])->toHaveCount(1)
        ->and($parsed['skipped'][0])->toStartWith('entry at byte');
});

it('reads a Google Scholar CSV export by its column names', function (): void {
    $parsed = CsvParser::parse(importFixture('scholar-export.csv'));

    expect($parsed['candidates'])->toHaveCount(2)
        ->and($parsed['candidates'][0]->journal)->toBe('Journal of Psychoceramics', '"Publication" is the journal')
        ->and($parsed['candidates'][0]->publicationDate)->toBe('2008')
        ->and(array_column($parsed['candidates'][0]->authors, 'name'))->toBe(['J Carberry', 'JJ Quinn'])
        ->and($parsed['candidates'][0]->doi)->toBeNull('Scholar exports carry no DOI');
});

it('reads a hand-kept semicolon CSV with identifiers, and skips a row with nothing to key on', function (): void {
    $parsed = CsvParser::parse(importFixture('hand-kept.csv'));

    expect($parsed['candidates'])->toHaveCount(3)
        ->and($parsed['skipped'])->toBe([]);

    $first = $parsed['candidates'][0];

    expect($first->doi)->toBe('10.1161/CIRCULATIONAHA.125.074645')
        ->and($first->pmid)->toBe('40788977')
        ->and($first->publicationDate)->toBe('2025-08-12')
        ->and(array_column($first->authors, 'name'))->toBe(['Berry C', 'Carberry J', 'Dharmakumar R'])
        ->and($first->abstract)->toBe('A short abstract.')
        ->and($parsed['candidates'][2]->title)->toBeNull()
        ->and($parsed['candidates'][2]->doi)->toBe('10.1000/only-a-doi');
});

it('refuses a CSV whose header has nothing to key on, naming the columns it saw', function (): void {
    expect(static fn () => CsvParser::parse("name,year\nA,2020"))->toThrow(InvalidArgumentException::class, 'Found: name, year');
    expect(static fn () => CsvParser::parse("   \n"))->toThrow(InvalidArgumentException::class);
});

it('skips a data row with no title, doi or pmid and says which row', function (): void {
    $parsed = CsvParser::parse("title,doi\nA,10.1000/a\n,\n,10.1000/c");

    expect($parsed['candidates'])->toHaveCount(2)
        ->and($parsed['skipped'])->toBe(['row 3: no title, doi or pmid']);
});
