<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

/**
 * One page of a decoded `esearch.fcgi` JSON response.
 *
 * Framework free. The point of pinning this shape is `count` versus
 * `idlist`: esearch returns at most `retmax` ids per page, and its default is
 * twenty. A caller that reads the id list without reading the count gets the
 * twenty most recent papers and no hint that there were sixty-three.
 */
final readonly class EsearchResult
{
    /**
     * @param  list<string>  $ids  PMIDs on this page, most recent first
     * @param  int  $count  Total matches for the query, across all pages
     * @param  int  $retstart  Offset of this page
     */
    public function __construct(
        public array $ids,
        public int $count,
        public int $retstart,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     *
     * @throws UnexpectedNcbiResponse
     */
    public static function fromDecoded(array $decoded): self
    {
        $result = $decoded['esearchresult'] ?? null;

        if (! \is_array($result)) {
            throw UnexpectedNcbiResponse::missing('"esearchresult"', 'esearch');
        }

        // NCBI reports an error this way (a bad term, a bad field name) with
        // HTTP 200, so it has to be read here rather than off the status.
        if (isset($result['ERROR']) && \is_string($result['ERROR'])) {
            throw new UnexpectedNcbiResponse('NCBI esearch reported an error: '.$result['ERROR']);
        }

        $idlist = $result['idlist'] ?? null;

        if (! \is_array($idlist) || ! \is_numeric($result['count'] ?? null)) {
            throw UnexpectedNcbiResponse::missing('"idlist" or "count"', 'esearch');
        }

        $ids = [];

        foreach ($idlist as $id) {
            if (\is_string($id) || \is_int($id)) {
                $ids[] = (string) $id;
            }
        }

        return new self($ids, (int) $result['count'], (int) ($result['retstart'] ?? 0));
    }

    /**
     * Whether a later page exists.
     */
    public function hasMore(): bool
    {
        return $this->ids !== [] && $this->retstart + \count($this->ids) < $this->count;
    }
}
