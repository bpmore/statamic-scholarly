<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Tests\Support;

use Bpmore\Scholarly\Contracts\PublicationSource;
use Bpmore\Scholarly\Fetching\FetchOutcome;
use Statamic\Contracts\Entries\Entry;

/**
 * A source that answers whatever the test told it to, and remembers what it
 * was asked.
 */
final class FakeSource implements PublicationSource
{
    /** @var list<array{researcher: string, since: string|null}> */
    public array $calls = [];

    public function __construct(
        private readonly string $key,
        private FetchOutcome $outcome,
        private readonly bool $configured = true,
    ) {}

    public function willReturn(FetchOutcome $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function fetchForResearcher(Entry $researcher, ?string $since = null): FetchOutcome
    {
        $this->calls[] = ['researcher' => (string) $researcher->id(), 'since' => $since];

        return $this->outcome;
    }

    public function sourceKey(): string
    {
        return $this->key;
    }
}
