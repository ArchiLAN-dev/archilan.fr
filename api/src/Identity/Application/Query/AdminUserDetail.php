<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

/**
 * Identity panel of the admin user sheet (story 36.1). Carries what the directory row already showed
 * plus what only a detail view has room for: the public slug and whether the email was ever verified.
 *
 * Deliberately not reusing {@see \App\Identity\Application\Command\AdminUserView} - that record is the
 * write commands' return shape, and this read will grow with the epic's other panels (36.2 to 36.5)
 * without dragging the write path along.
 */
final readonly class AdminUserDetail
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public ?string $displayName,
        public ?string $slug,
        public string $role,
        public array $roles,
        public string $status,
        public bool $emailVerified,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }
}
