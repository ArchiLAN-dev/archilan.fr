<?php

declare(strict_types=1);

namespace App\Sessions\Application;

interface LeaderboardQueryInterface
{
    /**
     * @return array{list<array{slug: string, displayName: string, value: int}>, int}
     */
    public function computeAggregatePage(string $axis, ?string $eventId, int $limit, int $offset): array;

    /**
     * @return array{list<array{slug: string, displayName: string, value: int}>, int}
     */
    public function computeSpeedPage(?string $eventId, int $limit, int $offset): array;
}
