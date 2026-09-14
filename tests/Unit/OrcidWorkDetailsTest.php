<?php

declare(strict_types=1);

use Bpmore\Scholarly\Orcid\OrcidWorkDetails;
use Bpmore\Scholarly\Orcid\UnexpectedOrcidResponse;

/**
 * Recorded from ORCID on 2026-09-13: two real works of the test persona and
 * one invalid put-code, which comes back as an error item with no code.
 */
function orcidBulk(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../__fixtures__/orcid/0000-0002-1825-0097-works-bulk.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('reads contributors by put-code and leaves out the error items', function (): void {
    $details = OrcidWorkDetails::parse(orcidBulk());

    expect(array_keys($details))->toBe([4562455, 19980729])
        ->and($details[4562455]['authors'])->toBe([])
        ->and($details[4562455]['abstract'])->toBeNull()
        ->and($details[19980729]['authors'])->toBe([
            ['name' => 'Quinn, J.J.', 'affiliation' => null, 'orcid' => null],
            ['name' => 'Carberry, J.S.', 'affiliation' => null, 'orcid' => null],
        ]);
});

it('reads a contributor ORCID iD and the description when present', function (): void {
    $details = OrcidWorkDetails::parse(['bulk' => [['work' => [
        'put-code' => 7,
        'short-description' => '  An abstract.  ',
        'contributors' => ['contributor' => [
            ['credit-name' => ['value' => 'Carberry J'], 'contributor-orcid' => ['path' => '0000-0002-1825-0097']],
            ['credit-name' => ['value' => 'Nobody'], 'contributor-orcid' => ['path' => 'not-an-id']],
            ['credit-name' => null],
        ]],
    ]]]]);

    expect($details[7]['abstract'])->toBe('An abstract.')
        ->and($details[7]['authors'])->toBe([
            ['name' => 'Carberry J', 'affiliation' => null, 'orcid' => '0000-0002-1825-0097'],
            ['name' => 'Nobody', 'affiliation' => null, 'orcid' => null],
        ]);
});

it('refuses a body that is not a bulk response', function (): void {
    expect(static fn () => OrcidWorkDetails::parse(['group' => []]))->toThrow(UnexpectedOrcidResponse::class);
});
