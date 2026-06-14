<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshToken;

final class RefreshTokenFactory
{
    public const TOKEN_TTL_LONG_DAYS = 30;

    public const TOKEN_TTL_SHORT_DAYS = 1;

    /**
     * @return array{rawToken: string, entity: RefreshToken}
     */
    public function issue(
        string $userId,
        \DateTimeImmutable $now,
        ?string $userAgent = null,
        bool $rememberMe = true,
        ?string $familyId = null,
    ): array {
        $ttlDays = $rememberMe ? self::TOKEN_TTL_LONG_DAYS : self::TOKEN_TTL_SHORT_DAYS;
        $rawToken = $this->generateRawToken();
        $expiresAt = $now->modify(sprintf('+%d days', $ttlDays));
        $entity = RefreshToken::issue($userId, $rawToken, $expiresAt, $now, $userAgent, $rememberMe, $familyId);

        return ['rawToken' => $rawToken, 'entity' => $entity];
    }

    private function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }
}
