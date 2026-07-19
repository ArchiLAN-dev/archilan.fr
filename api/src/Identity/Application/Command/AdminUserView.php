<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

/**
 * Admin-facing view of a user, returned by the admin write commands that mutate or create a user
 * ({@see AdminChangeUserRole}, {@see AdminCreateAdminAccount}). The controller serializes it verbatim
 * as the `data` payload.
 */
final readonly class AdminUserView
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public ?string $displayName,
        public string $role,
        public array $roles,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }
}
