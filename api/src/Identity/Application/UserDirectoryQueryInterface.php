<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface UserDirectoryQueryInterface
{
    /**
     * @return list<array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}>
     */
    public function search(?string $query, ?string $role): array;
}
