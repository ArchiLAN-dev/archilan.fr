<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/**
 * The secret the site shares with the blind test app (story 41.40), and the app's address. Both sides sign with
 * HMAC-SHA256: the site its participation tokens, the app its credits. Without a secret or an address the blind
 * test is unavailable.
 */
final readonly class BlindTestLink
{
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
     * The address a member opens to play: the app, carrying `{userId, eventId, displayName, exp}` and its signature.
     *
     * @param array{userId: string, eventId: string, displayName: string, exp: int} $claims
     */
    public function joinUrl(array $claims): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($claims, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $token = $payload.'.'.$this->sign($payload);
        $base = rtrim($this->appUrl, '/');

        return $base.(str_contains($base, '?') ? '&' : '?').'token='.rawurlencode($token);
    }
}
