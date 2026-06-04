<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface EmailConfirmationTokenRepositoryInterface
{
    public function findByTokenHash(string $hash): ?EmailConfirmationToken;

    public function save(EmailConfirmationToken $token): void;

    public function revokeExistingForUser(string $userId, \DateTimeImmutable $now): void;
}
