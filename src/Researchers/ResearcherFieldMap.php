<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Researchers;

/**
 * Reads researcher identifiers from whatever fields a host happens to use.
 *
 * This class is the whole portability story in one place. One site keeps ORCID
 * iDs in `orcid_id`, another in `orcid`, a third in `identifiers.orcid`. An
 * addon that hardcodes any of those works on exactly one site, and the failure
 * is silent: the field reads empty, no publications are found, and that looks
 * identical to a researcher who has published nothing.
 *
 * Framework free. It takes a map and an array of values, so it can be tested
 * without an entry.
 */
final readonly class ResearcherFieldMap
{
    /** The identifier kinds this addon knows how to use. */
    public const array KINDS = [
        'orcid',
        'pubmed_author',
        'scopus_author',
        'researcher_id',
        'google_scholar',
    ];

    /**
     * @param  array<string, string>  $map  Kind to the host's field handle
     */
    public function __construct(private array $map) {}

    /**
     * @param  array<string, mixed>  $config  The `researchers.fields` config array
     */
    public static function fromConfig(array $config): self
    {
        $map = [];

        foreach (self::KINDS as $kind) {
            $handle = $config[$kind] ?? null;

            if (\is_string($handle) && \trim($handle) !== '') {
                $map[$kind] = \trim($handle);
            }
        }

        return new self($map);
    }

    /**
     * The host's field handle for a kind, or null when the host does not map it.
     */
    public function handleFor(string $kind): ?string
    {
        return $this->map[$kind] ?? null;
    }

    /**
     * Read one identifier out of an entry's values.
     *
     * Returns null both when the kind is unmapped and when the mapped field is
     * empty. Callers that need to tell those apart ask `handleFor()` as well,
     * because "this site does not record ORCID" and "this researcher has no
     * ORCID" lead to different advice.
     *
     * @param  array<string, mixed>  $values
     */
    public function read(string $kind, array $values): ?string
    {
        $handle = $this->handleFor($kind);

        if ($handle === null) {
            return null;
        }

        $value = $values[$handle] ?? null;

        if (! \is_string($value) && ! \is_int($value)) {
            return null;
        }

        $value = \trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Every identifier this researcher actually carries.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function readAll(array $values): array
    {
        $found = [];

        foreach (self::KINDS as $kind) {
            $value = $this->read($kind, $values);

            if ($value !== null) {
                $found[$kind] = $value;
            }
        }

        return $found;
    }
}
