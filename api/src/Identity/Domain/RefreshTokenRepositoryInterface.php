<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface RefreshTokenRepositoryInterface
{
    public function save(RefreshToken $token): void;

    public function persist(RefreshToken $token): void;

    public function findByTokenHash(string $hash): ?RefreshToken;

    public function revokeAllForUser(string $userId): void;

    /**
     * Revoke every still-active token in one rotation family (one login lineage),
     * leaving the user's other devices/sessions untouched.
     */
    public function revokeFamily(string $familyId): void;

    public function deleteExpiredBefore(\DateTimeImmutable $threshold): int;

    public function deleteStale(\DateTimeImmutable $now): int;

    public function flush(): void;
}
