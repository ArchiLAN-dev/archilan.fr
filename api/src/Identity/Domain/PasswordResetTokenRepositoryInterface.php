<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface PasswordResetTokenRepositoryInterface
{
    public function findByTokenHash(string $hash): ?PasswordResetToken;

    public function save(PasswordResetToken $token): void;

    public function revokeExistingForUser(string $userId, \DateTimeImmutable $now): void;

    public function flush(): void;
}
