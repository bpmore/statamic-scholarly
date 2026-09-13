<?php

declare(strict_types=1);

use Bpmore\Scholarly\Publications\PublicationKey;

/*
 * The same paper arrives from several sources wearing different clothes.
 * Comparing those as strings finds several papers, so these cases pin the
 * normalisation that stops it.
 */

it('reduces every resolver form of a DOI to the same key', function (string $input): void {
    expect(PublicationKey::normaliseDoi($input))->toBe('10.1001/jama.2020.1585');
})->with([
    '10.1001/jama.2020.1585',
    '10.1001/JAMA.2020.1585',
    'https://doi.org/10.1001/jama.2020.1585',
    'http://dx.doi.org/10.1001/jama.2020.1585',
    'doi:10.1001/jama.2020.1585',
    'https://doi.org/doi:10.1001/jama.2020.1585',
    '  10.1001/jama.2020.1585  ',
]);

it('refuses a value that is not a DOI rather than guessing', function (?string $input): void {
    // A wrong key merges two different papers into one, which is worse than
    // failing to merge two copies of one.
    expect(PublicationKey::normaliseDoi($input))->toBeNull();
})->with([
    'jama.2020.1585',
    // A registrant prefix is four to nine digits, so this is not a DOI.
    '10.1/aaa',
    '',
    null,
]);

it('reduces a PMID to its digits', function (string|int $input): void {
    expect(PublicationKey::normalisePmid($input))->toBe('32208485');
})->with([32208485, '32208485', 'PMID: 32208485', '  32208485 ']);

it('refuses a PMID with a leading zero or a non-digit', function (?string $input): void {
    expect(PublicationKey::normalisePmid($input))->toBeNull();
})->with(['032208485', 'abc', '', null]);

it('folds punctuation the sources disagree about out of a title', function (): void {
    expect(PublicationKey::normaliseTitle('A Study of X-Ray "Imaging".'))
        ->toBe(PublicationKey::normaliseTitle('a study of x ray imaging'));
});
