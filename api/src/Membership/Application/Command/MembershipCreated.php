<?php

declare(strict_types=1);

namespace App\Membership\Application\Command;

/**
 * Result of {@see AdminCreateMembership::create}: the freshly created membership as the admin UI renders it.
 */
final readonly class MembershipCreated
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $status,
        public string $startedAt,
        public string $expiresAt,
        public string $source,
        public ?string $adminNote,
    ) {
    }
}
