<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface UserRepositoryInterface
{
    public function findById(string $id): ?User;

    /**
     * @param list<string> $ids
     *
     * @return list<User>
     */
    public function findByIds(array $ids): array;

    public function findByEmailCanonical(string $emailCanonical): ?User;

    public function findBySlug(string $slug): ?User;

    public function findByDiscordId(string $discordId): ?User;

    public function existsBySlug(string $slug): bool;

    /**
     * @return list<User>
     */
    public function findAllNotDeleted(): array;

    public function save(User $user): void;

    public function flush(): void;
}
