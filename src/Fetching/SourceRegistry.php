<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

use Bpmore\Scholarly\Contracts\PublicationSource;
use InvalidArgumentException;

/**
 * Every source the site knows about, in the order they are consulted.
 *
 * Order matters downstream: the snapshot prefers the first source with a
 * value, so a claim (ORCID) is listed before an estimate (PubMed). Hosts add
 * a source by tagging it `scholarly.sources`; it goes after the shipped ones.
 */
final readonly class SourceRegistry
{
    /** @var list<PublicationSource> */
    private array $sources;

    public function __construct(PublicationSource ...$sources)
    {
        $this->sources = \array_values($sources);
    }

    /**
     * @return list<PublicationSource>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * One source by key, or every source when no key is given.
     *
     * An unknown key is an error rather than an empty list: a typo in
     * `--source=orcd` that quietly fetched nothing would be a run that looks
     * like a researcher with no publications.
     *
     * @return list<PublicationSource>
     */
    public function select(?string $key): array
    {
        if ($key === null) {
            return $this->sources;
        }

        foreach ($this->sources as $source) {
            if ($source->sourceKey() === $key) {
                return [$source];
            }
        }

        throw new InvalidArgumentException(\sprintf(
            'No publication source is registered as "%s". Known: %s',
            $key,
            \implode(', ', \array_map(static fn (PublicationSource $s): string => $s->sourceKey(), $this->sources)) ?: 'none',
        ));
    }
}
