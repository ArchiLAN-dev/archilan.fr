<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Application\DolibarrClientInterface;

final readonly class NullDolibarrClient implements DolibarrClientInterface
{
    public function upsertMember(
        string $email,
        string $displayName,
        string $status,
        ?\DateTimeImmutable $expiresAt,
    ): void {
        // no-op stub for tests
    }
}
