<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Community\Application\Query\FriendCircleQuery;

final readonly class LeaderboardQuery
{
    public function __construct(
        private LeaderboardQueryInterface $query,
        private FriendCircleQuery $friendCircle,
    ) {
    }

    /**
     * @param bool $friendsOnly story 43.8: the viewer and their friends only, ranked among themselves; nobody for an
     *                          anonymous visitor (the directory's rule)
     *
     * @return array{list<array{slug: string, displayName: string, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, title: array{label: string, rarity: string, icon: string|null, access: string}|null, value: int}>, int}
     */
    public function computeAggregatePage(string $axis, ?string $eventId, int $limit, int $offset, bool $friendsOnly = false, ?string $viewerId = null): array
    {
        $userIds = $this->scope($friendsOnly, $viewerId);

        return [] === $userIds ? [[], 0] : $this->query->computeAggregatePage($axis, $eventId, $limit, $offset, $userIds);
    }

    /**
     * @return array{list<array{slug: string, displayName: string, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, title: array{label: string, rarity: string, icon: string|null, access: string}|null, value: int}>, int}
     */
    public function computeSpeedPage(?string $eventId, int $limit, int $offset, bool $friendsOnly = false, ?string $viewerId = null): array
    {
        $userIds = $this->scope($friendsOnly, $viewerId);

        return [] === $userIds ? [[], 0] : $this->query->computeSpeedPage($eventId, $limit, $offset, $userIds);
    }

    /**
     * @return list<string>|null null for everyone, an empty list for nobody
     */
    private function scope(bool $friendsOnly, ?string $viewerId): ?array
    {
        if (!$friendsOnly) {
            return null;
        }

        return null === $viewerId ? [] : $this->friendCircle->memberIds($viewerId);
    }
}
