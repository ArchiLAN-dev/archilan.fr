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
     * True when $slug was released by ANOTHER user whose change is still within the reservation window
     * (slug_changed_at > $cutoff). Used to block taking a recently-freed slug; the former owner
     * ($exceptUserId) is excluded so they can reclaim their own previous slug.
     */
    public function isSlugReserved(string $slug, \DateTimeImmutable $cutoff, string $exceptUserId): bool;

    /**
     * @return list<User>
     */
    public function findAllNotDeleted(): array;

    public function save(User $user): void;

    public function flush(): void;
}
