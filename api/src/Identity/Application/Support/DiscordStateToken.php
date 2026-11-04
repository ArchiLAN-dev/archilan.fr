<?php

declare(strict_types=1);

namespace App\Identity\Application\Support;

use Psr\Clock\ClockInterface;

/**
 * The `state` of the Discord OAuth round trip. The signature proves we issued it; the nonce it carries ties it to
 * the browser that started the flow, which holds the same nonce in a cookie (story 50.3).
 */
final readonly class DiscordStateToken
{
    public const int MAX_AGE_SECONDS = 600;

    public function __construct(
        private string $appSecret,
        private ClockInterface $clock,
    ) {
    }

    public function newNonce(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function generate(string $purpose, string $nonce, string $userId = ''): string
    {
        $data = $purpose.':'.$userId.':'.$this->clock->now()->getTimestamp().':'.$nonce;
        $hmac = hash_hmac('sha256', $data, $this->appSecret);

        return rtrim(strtr(base64_encode($data.':'.$hmac), '+/', '-_'), '=');
    }

    /**
     * Returns the userId embedded in the token (may be empty string for auth flow).
     * Returns null when the token is invalid, expired, for another purpose, or issued to another browser.
     */
    public function verify(string $token, string $expectedPurpose, string $browserNonce): ?string
    {
        if ('' === $browserNonce) {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (false === $decoded) {
            return null;
        }

        $lastColon = strrpos($decoded, ':');
        if (false === $lastColon) {
            return null;
        }

        $hmac = substr($decoded, $lastColon + 1);
        $data = substr($decoded, 0, $lastColon);

        if (!hash_equals(hash_hmac('sha256', $data, $this->appSecret), $hmac)) {
            return null;
        }

        $parts = explode(':', $data, 4);
        if (4 !== count($parts)) {
            return null;
        }

        [$purpose, $userId, $timestamp, $nonce] = $parts;

        if ($purpose !== $expectedPurpose || !hash_equals($nonce, $browserNonce)) {
            return null;
        }

        if ((int) $timestamp + self::MAX_AGE_SECONDS < $this->clock->now()->getTimestamp()) {
            return null;
        }

        return $userId;
    }
}
