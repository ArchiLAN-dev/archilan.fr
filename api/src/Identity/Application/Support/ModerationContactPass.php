<?php

declare(strict_types=1);

namespace App\Identity\Application\Support;

use Psr\Clock\ClockInterface;

/**
 * The pass a blocked member gets once their credentials checked out (story 39.2): it only lets them read
 * their sanction and write to the moderation, on {@see self::COOKIE_PATH}, never log in.
 *
 * Signed with a key of its own derived from the app secret, so a session cookie is no pass and a pass is no
 * session, even though both carry a user id.
 */
final readonly class ModerationContactPass
{
    public const string COOKIE_NAME = 'archilan_moderation_contact';
    public const string COOKIE_PATH = '/api/v1/moderation-contact';
    public const int TTL = 3600;

    private string $key;

    public function __construct(
        string $appSecret,
        private ClockInterface $clock,
    ) {
        $this->key = hash_hmac('sha256', 'moderation_contact', $appSecret, true);
    }

    public function issue(string $userId): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'sub' => $userId,
            'exp' => $this->clock->now()->getTimestamp() + self::TTL,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->sign($payload);
    }

    /** The member the pass was issued to, or null for a forged, foreign or expired one. */
    public function verify(string $value): ?string
    {
        $parts = explode('.', $value, 2);
        if (2 !== count($parts) || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }

        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        try {
            $payload = false !== $decoded ? json_decode($decoded, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload) || !is_string($payload['sub'] ?? null) || !is_int($payload['exp'] ?? null)) {
            return null;
        }

        return $payload['exp'] > $this->clock->now()->getTimestamp() ? $payload['sub'] : null;
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->key, true));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
