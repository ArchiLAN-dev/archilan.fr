<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class AuthSessionSigner
{
    public const COOKIE_NAME = '__Host-archilan_session';
    public const ACCESS_TOKEN_TTL = 900;

    public function __construct(private string $appSecret)
    {
    }

    public function sign(string $userId): string
    {
        $now = time();
        $payload = $this->base64UrlEncode(json_encode([
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TOKEN_TTL,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->appSecret, true));

        return $payload.'.'.$signature;
    }

    public function verify(string $cookieValue): ?string
    {
        $parts = explode('.', $cookieValue, 2);

        if (2 !== count($parts)) {
            return null;
        }

        [$payload, $signature] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->appSecret, true));

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($payload);
        if (false === $decoded) {
            return null;
        }

        try {
            $decodedPayload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decodedPayload) || !is_string($decodedPayload['sub'] ?? null)) {
            return null;
        }

        if (!is_int($decodedPayload['exp'] ?? null) || $decodedPayload['exp'] <= time()) {
            return null;
        }

        return $decodedPayload['sub'];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
