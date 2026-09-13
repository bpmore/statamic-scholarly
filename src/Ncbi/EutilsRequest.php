<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Ncbi;

/**
 * Builds an E-utilities query string.
 *
 * Framework free so the identification rules can be asserted without a network
 * or a container, which is what stops them regressing quietly.
 *
 * Three parameters NCBI asks every client for, and the reason each is here:
 *
 *   tool     names the software, so NCBI can see that many sites sending
 *            requests are one addon rather than a coordinated abuser.
 *   email    is who they contact before blocking. Required: see
 *            MissingContactEmail for why this refuses instead of warning.
 *   api_key  raises the ceiling from three requests a second to ten. Optional,
 *            and its absence changes the pacing rather than the behaviour.
 */
final readonly class EutilsRequest
{
    public const string BASE = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';

    /** Requests per second NCBI allows an unidentified-by-key client. */
    public const int RATE_WITHOUT_KEY = 3;

    /** Requests per second NCBI allows with an API key. */
    public const int RATE_WITH_KEY = 10;

    public function __construct(
        private string $tool,
        private ?string $email,
        private ?string $apiKey = null,
    ) {}

    /**
     * The permitted requests per second for this configuration.
     */
    public function ratePerSecond(): int
    {
        return $this->hasApiKey() ? self::RATE_WITH_KEY : self::RATE_WITHOUT_KEY;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && \trim($this->apiKey) !== '';
    }

    /**
     * Full URL for an endpoint, with identification merged into the query.
     *
     * @param  array<string, string|int>  $query
     *
     * @throws MissingContactEmail when no contact email is configured
     */
    public function url(string $endpoint, array $query): string
    {
        return self::BASE.$endpoint.'?'.\http_build_query($this->query($query));
    }

    /**
     * The query parameters, identification included.
     *
     * Caller parameters are merged first so identification cannot be
     * overwritten by a caller that happens to pass its own `tool`.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, string|int>
     *
     * @throws MissingContactEmail when no contact email is configured
     */
    public function query(array $query = []): array
    {
        if ($this->email === null || \trim($this->email) === '') {
            throw MissingContactEmail::make();
        }

        $identified = [
            ...$query,
            'tool' => $this->tool,
            'email' => \trim($this->email),
        ];

        if ($this->hasApiKey()) {
            $identified['api_key'] = \trim((string) $this->apiKey);
        }

        return $identified;
    }
}
