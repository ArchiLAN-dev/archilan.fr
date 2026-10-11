<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\FriendFavorite;

interface FriendFavoriteRepositoryInterface
{
    /**
     * The friends a member starred, as a set: favorite user id => true.
     *
     * @return array<string, true>
     */
    public function favoriteIds(string $userId): array;

    /**
     * Who starred a member (story 43.11b): the ones told of their activity.
     *
     * @return list<string>
     */
    public function starredBy(string $favoriteUserId): array;

    public function find(string $userId, string $favoriteUserId): ?FriendFavorite;

    public function count(string $userId): int;

    public function save(FriendFavorite $favorite): void;

    public function remove(FriendFavorite $favorite): void;

    /** Both ways: an ended friendship or a block takes the star away on each side. */
    public function removeBetween(string $a, string $b): void;
}
