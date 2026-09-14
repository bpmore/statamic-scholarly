<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a review digest to a URL the site configured, signed.
 *
 * One POST per notification, so a site with three verifiers gets three
 * posts, each saying who it was for. The body is JSON; the signature is
 * an HMAC-SHA256 of the exact body under the configured secret, in
 * `X-Scholarly-Signature`, so the receiver can tell this addon from
 * anyone who found the URL.
 *
 * A failed post is logged and does not stop the mail: the webhook is an
 * extra ear, not the only one.
 */
final class WebhookChannel
{
    public const SIGNATURE_HEADER = 'X-Scholarly-Signature';

    public function __construct(
        private readonly string $url,
        private readonly ?string $secret,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function send(mixed $notifiable, CandidatesAwaitingReview $notification): void
    {
        $body = (string) \json_encode($notification->toWebhook($notifiable), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type' => 'application/json'];

        if ($this->secret !== null && $this->secret !== '') {
            $headers[self::SIGNATURE_HEADER] = 'sha256='.\hash_hmac('sha256', $body, $this->secret);
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)->withHeaders($headers)->withBody($body, 'application/json')->post($this->url);
        } catch (ConnectionException $e) {
            Log::warning('Scholarly could not reach the review webhook.', ['url' => $this->url, 'reason' => $e->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('Scholarly review webhook refused the post.', ['url' => $this->url, 'status' => $response->status()]);
        }
    }
}
