<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface EmailConfirmationTokenRepositoryInterface
{
    public function findByTokenHash(string $hash): ?EmailConfirmationToken;

    public function save(EmailConfirmationToken $token): void;

    public function revokeExistingForUser(string $userId, \DateTimeImmutable $now): void;

    /**
     * Delete tokens that are expired, or confirmed (consumed) before the grace threshold.
     *
     * @return int number of deleted rows
     */
    public function deleteStale(\DateTimeImmutable $now, \DateTimeImmutable $consumedBefore): int;
}
