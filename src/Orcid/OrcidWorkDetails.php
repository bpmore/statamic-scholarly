<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Orcid;

use Bpmore\Scholarly\Rules\IdentifierFormat;

/**
 * Reads contributors and the short description out of a bulk works
 * response (`GET /v3.0/{orcid}/works/{put-code},{put-code}`).
 *
 * The summary listing has neither, and the contributor list is the one
 * place ORCID reports a co-author's own iD. Framework free, pinned against
 * a recorded response.
 */
final class OrcidWorkDetails
{
    /**
     * @param  array<string, mixed>  $decoded  The JSON-decoded bulk response
     * @return array<int, array{abstract: string|null, authors: list<array{name: string, affiliation: null, orcid: string|null}>}|null>
     *                                                                                                                        Keyed by put-code; null where ORCID reported an error for that code
     *
     * @throws UnexpectedOrcidResponse when the body is not a bulk response
     */
    public static function parse(array $decoded): array
    {
        $bulk = $decoded['bulk'] ?? null;

        if (! \is_array($bulk)) {
            throw UnexpectedOrcidResponse::missing('the "bulk" list');
        }

        $out = [];

        foreach ($bulk as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $work = $item['work'] ?? null;

            if (! \is_array($work)) {
                // An error item carries no put-code, so which code failed is
                // known only by the caller's request order. The caller fills
                // in nulls for codes missing here.
                continue;
            }

            $putCode = $work['put-code'] ?? null;

            if (! \is_int($putCode) && ! (\is_string($putCode) && \ctype_digit($putCode))) {
                continue;
            }

            $out[(int) $putCode] = [
                'abstract' => self::text($work['short-description'] ?? null),
                'authors' => self::contributors($work['contributors'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, affiliation: null, orcid: string|null}>
     */
    private static function contributors(mixed $contributors): array
    {
        $authors = [];

        foreach (\is_array($contributors) ? ($contributors['contributor'] ?? []) : [] as $contributor) {
            if (! \is_array($contributor)) {
                continue;
            }

            $name = self::text($contributor['credit-name']['value'] ?? null);

            if ($name === null) {
                continue;
            }

            $orcid = self::text($contributor['contributor-orcid']['path'] ?? null);

            $authors[] = [
                'name' => $name,
                'affiliation' => null,
                'orcid' => $orcid !== null && IdentifierFormat::isOrcid($orcid) ? $orcid : null,
            ];
        }

        return $authors;
    }

    private static function text(mixed $value): ?string
    {
        if (! \is_string($value)) {
            return null;
        }

        $value = \trim($value);

        return $value === '' ? null : $value;
    }
}
