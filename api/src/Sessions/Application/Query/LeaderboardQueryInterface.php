<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

interface LeaderboardQueryInterface
{
    /**
     * @param list<string>|null $userIds story 43.8: only these members, ranked among themselves (null = everyone)
     *
     * @return array{list<array{slug: string, displayName: string, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, title: array{label: string, rarity: string, icon: string|null, access: string}|null, value: int}>, int}
     */
    public function computeAggregatePage(string $axis, ?string $eventId, int $limit, int $offset, ?array $userIds = null): array;

    /**
     * @param list<string>|null $userIds story 43.8: only these members, ranked among themselves (null = everyone)
     *
     * @return array{list<array{slug: string, displayName: string, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, title: array{label: string, rarity: string, icon: string|null, access: string}|null, value: int}>, int}
     */
    public function computeSpeedPage(?string $eventId, int $limit, int $offset, ?array $userIds = null): array;
}
