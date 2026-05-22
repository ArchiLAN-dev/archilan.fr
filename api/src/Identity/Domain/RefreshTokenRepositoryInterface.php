<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface RefreshTokenRepositoryInterface
{
    public function save(RefreshToken $token): void;

    public function persist(RefreshToken $token): void;

    public function findByTokenHash(string $hash): ?RefreshToken;

    public function revokeAllForUser(string $userId): void;

    public function deleteExpiredBefore(\DateTimeImmutable $threshold): int;

    public function deleteStale(\DateTimeImmutable $now): int;

    public function flush(): void;
}
