<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

interface LeaderboardQueryInterface
{
    /**
     * @return array{list<array{slug: string, displayName: string, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, value: int}>, int}
     */
    public function computeAggregatePage(string $axis, ?string $eventId, int $limit, int $offset): array;

    /**
     * @return array{list<array{slug: string, displayName: string, avatarUrl: string|null, avatarAnimatedUrl: string|null, avatarFraming: array{x: int, y: int, zoom: int}|null, avatarFrame: string|null, nameStyle: string|null, value: int}>, int}
     */
    public function computeSpeedPage(?string $eventId, int $limit, int $offset): array;
}
