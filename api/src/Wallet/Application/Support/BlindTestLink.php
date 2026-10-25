<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/**
 * The secret the site shares with the blind test app (story 41.40), and the app's address. The app signs its calls
 * (credits, login code exchange) with HMAC-SHA256. Without a secret or an address the blind test is unavailable.
 */
final readonly class BlindTestLink
{
    public const int MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private string $appUrl,
        private string $secret,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->appUrl) && '' !== $this->secret;
    }

    public function sign(string $message): string
    {
        return hash_hmac('sha256', $message, $this->secret);
    }

    public function verify(string $message, string $signature): bool
    {
        return '' !== $this->secret && hash_equals($this->sign($message), strtolower(trim($signature)));
    }

    /**
     * A request the app signed (story 41.45): `{timestamp}.{body}` under the shared secret, sent within 5 minutes.
     */
    public function verifyRequest(string $timestamp, string $signature, string $body, \DateTimeImmutable $now): bool
    {
        return ctype_digit($timestamp)
            && abs($now->getTimestamp() - (int) $timestamp) <= self::MAX_CLOCK_SKEW_SECONDS
            && $this->verify($timestamp.'.'.$body, $signature);
    }

    /** Where a member enters the app: it starts the login from there (story 41.45). */
    public function entryUrl(): string
    {
        return $this->base().'/connexion';
    }

    /** Where the site sends the member back with their one-time code. Built from the configuration, never a request. */
    public function callbackUrl(string $code, string $state): string
    {
        return $this->base().'/connexion/retour?'.http_build_query(['code' => $code, 'state' => $state]);
    }

    /** The app's console for this event (story 41.46), or null when the app is not configured. */
    public function consoleUrl(string $eventId): ?string
    {
        return $this->isConfigured() ? $this->base().'/admin?'.http_build_query(['event' => $eventId]) : null;
    }

    private function base(): string
    {
        return rtrim(trim($this->appUrl), '/');
    }
}
