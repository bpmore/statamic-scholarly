<?php

declare(strict_types=1);

use Bpmore\Scholarly\Ncbi\EutilsRequest;
use Bpmore\Scholarly\Ncbi\MissingContactEmail;

it('identifies itself on every request', function (): void {
    $query = (new EutilsRequest('statamic-scholarly', 'dev@example.org'))
        ->query(['db' => 'pubmed', 'id' => '12345']);

    expect($query['tool'])->toBe('statamic-scholarly');
    expect($query['email'])->toBe('dev@example.org');
    expect($query['db'])->toBe('pubmed');
});

it('refuses to query at all when no contact email is configured', function (?string $email): void {
    // Refusing is the safe direction. A run that did not happen is visible; a
    // run that happened anonymously is not, until NCBI blocks the site.
    (new EutilsRequest('statamic-scholarly', $email))->query([]);
})->with([null, '', '   '])->throws(MissingContactEmail::class);

it('does not let a caller overwrite its identification', function (): void {
    $query = (new EutilsRequest('statamic-scholarly', 'dev@example.org'))
        ->query(['tool' => 'something-else', 'email' => 'other@example.org']);

    expect($query['tool'])->toBe('statamic-scholarly');
    expect($query['email'])->toBe('dev@example.org');
});

it('sends an API key only when one is configured', function (): void {
    expect((new EutilsRequest('t', 'e@example.org'))->query([]))->not->toHaveKey('api_key');
    expect((new EutilsRequest('t', 'e@example.org', 'abc123'))->query()['api_key'] ?? null)->toBe('abc123');
});

it('raises the permitted rate when an API key is present', function (): void {
    expect((new EutilsRequest('t', 'e@example.org'))->ratePerSecond())->toBe(3);
    expect((new EutilsRequest('t', 'e@example.org', 'abc123'))->ratePerSecond())->toBe(10);
});
