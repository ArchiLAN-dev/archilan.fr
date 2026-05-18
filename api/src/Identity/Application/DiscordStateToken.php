<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class DiscordStateToken
{
    private const MAX_AGE_SECONDS = 600;

    public function __construct(private string $appSecret)
    {
    }

    public function generate(string $purpose, string $userId = ''): string
    {
        $data = $purpose.':'.$userId.':'.time();
        $hmac = hash_hmac('sha256', $data, $this->appSecret);

        return rtrim(strtr(base64_encode($data.':'.$hmac), '+/', '-_'), '=');
    }

    /**
     * Returns the userId embedded in the token (may be empty string for auth flow).
     * Returns null when the token is invalid, expired, or purpose mismatch.
     */
    public function verify(string $token, string $expectedPurpose): ?string
    {
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

        $parts = explode(':', $data, 3);
        if (3 !== count($parts)) {
            return null;
        }

        [$purpose, $userId, $timestamp] = $parts;

        if ($purpose !== $expectedPurpose) {
            return null;
        }

        if ((int) $timestamp + self::MAX_AGE_SECONDS < time()) {
            return null;
        }

        return $userId;
    }
}
