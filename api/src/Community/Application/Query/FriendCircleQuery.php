<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Repository\FriendshipRepositoryInterface;

/**
 * A viewer and their accepted friends (story 43.8), to scope a ranking to people they know. A block removes the
 * friendship, so a blocked member is never in it; a friend without a listable card (banned, suspended, no slug) is
 * left out too. The viewer always counts.
 */
final readonly class FriendCircleQuery
{
    public function __construct(
        private FriendshipRepositoryInterface $friendships,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * @return list<string> the viewer's id first, then their listable friends'
     */
    public function memberIds(string $viewerId): array
    {
        $friendIds = [];
        foreach ($this->friendships->findAccepted($viewerId) as $friendship) {
            $friendIds[] = $friendship->otherParty($viewerId);
        }
        $listable = [] === $friendIds ? [] : array_keys($this->directory->cards($friendIds));

        return [$viewerId, ...array_values(array_filter($listable, static fn (string $id): bool => $id !== $viewerId))];
    }
}
