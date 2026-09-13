<?php

declare(strict_types=1);

use Bpmore\Scholarly\Ncbi\PubmedSearchQuery;

it('quotes and tags the author string the way PubMed expects', function (): void {
    expect((new PubmedSearchQuery('Carberry J'))->term())->toBe('"Carberry J"[Author]')
        ->and((new PubmedSearchQuery("o'brien ja"))->term())->toBe('"o\'brien ja"[Author]');
});

it('drops a double quote that would otherwise end the phrase early', function (): void {
    expect((new PubmedSearchQuery('Carberry" OR "Smith'))->term())->toBe('"Carberry OR Smith"[Author]');
});

it('always sends retmax, because the default page of twenty looks complete', function (): void {
    $params = (new PubmedSearchQuery('Carberry J'))->params();

    expect($params)->toBe([
        'db' => 'pubmed',
        'retmode' => 'json',
        'term' => '"Carberry J"[Author]',
        'retmax' => PubmedSearchQuery::PAGE_SIZE,
        'retstart' => 0,
    ]);

    expect((new PubmedSearchQuery('Carberry J'))->params(500)['retstart'])->toBe(500);
});

it('filters on modification date with both ends of the window', function (): void {
    $params = (new PubmedSearchQuery('Carberry J', new DateTimeImmutable('2020-01-05T10:00:00Z')))->params();

    expect($params['datetype'])->toBe('mdat')
        ->and($params['mindate'])->toBe('2020/01/05')
        ->and($params['maxdate'])->toBe('3000');
});

it('sends no date window at all when there is no since', function (): void {
    $params = (new PubmedSearchQuery('Carberry J'))->params();

    expect(array_key_exists('datetype', $params))->toBeFalse()
        ->and(array_key_exists('mindate', $params))->toBeFalse();
});
