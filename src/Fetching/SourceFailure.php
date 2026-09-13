<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

/**
 * One source that did not produce an answer, and why.
 *
 * This class exists because of the failure the whole addon is built to refuse:
 * zero publications is indistinguishable from a researcher who has none, an
 * expired credential, a rate limit block, a renamed API field and a typo in an
 * identifier. A run that cannot say which of those happened has not answered
 * the question it was asked.
 */
final readonly class SourceFailure
{
    public function __construct(
        public string $sourceKey,
        public Reason $reason,
        public string $detail,
    ) {}

    public function describe(): string
    {
        return \sprintf('%s: %s (%s)', $this->sourceKey, $this->reason->value, $this->detail);
    }
}
