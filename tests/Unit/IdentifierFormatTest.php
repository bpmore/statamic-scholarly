<?php

declare(strict_types=1);

use Bpmore\Scholarly\Rules\IdentifierFormat;

it('accepts a well formed ORCID iD including the X check digit', function (string $value): void {
    expect(IdentifierFormat::isOrcid($value))->toBeTrue();
})->with(['0000-0002-1825-0097', '0000-0002-1825-009X']);

it('rejects an ORCID iD that is not in the canonical form', function (string $value): void {
    expect(IdentifierFormat::isOrcid($value))->toBeFalse();
})->with(['0000-0002-1825-009x', '0000000218250097', '0000-0002-1825']);

it('accepts a Scopus Author ID of ten to twelve digits', function (): void {
    expect(IdentifierFormat::isScopusAuthorId('1234567890'))->toBeTrue();
    expect(IdentifierFormat::isScopusAuthorId('0234567890'))->toBeFalse();
});

it('accepts a Web of Science ResearcherID only in upper case', function (): void {
    expect(IdentifierFormat::isResearcherId('ABC-1234-2023'))->toBeTrue();
    expect(IdentifierFormat::isResearcherId('abc-1234-2023'))->toBeFalse();
});

it('accepts an unusual real surname in a PubMed author string', function (string $value): void {
    // Rejecting a real name is a worse failure than accepting a string that
    // finds nothing, so this is deliberately loose.
    expect(IdentifierFormat::isPubmedAuthorString($value))->toBeTrue();
})->with(['mccoy lm', "o'brien ja", 'de vries j', 'van der berg ab']);

it('rejects a PubMed author string containing digits', function (): void {
    expect(IdentifierFormat::isPubmedAuthorString('mccoy 12'))->toBeFalse();
});
