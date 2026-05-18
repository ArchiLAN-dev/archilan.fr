<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface DolibarrClientInterface
{
    public function upsertMember(
        string $email,
        string $displayName,
        string $status,
        ?\DateTimeImmutable $expiresAt,
    ): void;
}
