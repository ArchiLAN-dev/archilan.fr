<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\Friendship;

interface FriendshipRepositoryInterface
{
    public function findById(string $id): ?Friendship;

    public function findBetween(string $a, string $b): ?Friendship;

    public function areFriends(string $a, string $b): bool;

    /**
     * @return list<Friendship> accepted friendships involving the user
     */
    public function findAccepted(string $userId): array;

    /**
     * @return list<Friendship> pending requests addressed to the user
     */
    public function findIncomingPending(string $userId): array;

    /**
     * @return list<Friendship> pending requests sent by the user
     */
    public function findOutgoingPending(string $userId): array;

    public function save(Friendship $friendship): void;

    public function remove(Friendship $friendship): void;

    public function flush(): void;

    /** Story 43.19: one unit of work for a friendship ended and what goes with it (favourites, groups). */
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
