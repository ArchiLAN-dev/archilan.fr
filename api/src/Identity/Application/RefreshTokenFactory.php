<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshToken;

final class RefreshTokenFactory
{
    public const TOKEN_TTL_DAYS = 30;

    /**
     * @return array{rawToken: string, entity: RefreshToken}
     */
    public function issue(
        string $userId,
        \DateTimeImmutable $now,
        ?string $userAgent = null,
    ): array {
        $rawToken = $this->generateRawToken();
        $expiresAt = $now->modify(sprintf('+%d days', self::TOKEN_TTL_DAYS));
        $entity = RefreshToken::issue($userId, $rawToken, $expiresAt, $now, $userAgent);

        return ['rawToken' => $rawToken, 'entity' => $entity];
    }

    private function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }
}
