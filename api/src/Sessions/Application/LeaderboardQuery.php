<?php

declare(strict_types=1);

namespace App\Sessions\Application;

final readonly class LeaderboardQuery
{
    public function __construct(private LeaderboardQueryInterface $query)
    {
    }

    /**
     * @return array{list<array{slug: string, displayName: string, value: int}>, int}
     */
    public function computeAggregatePage(string $axis, ?string $eventId, int $limit, int $offset): array
    {
        return $this->query->computeAggregatePage($axis, $eventId, $limit, $offset);
    }

    /**
     * @return array{list<array{slug: string, displayName: string, value: int}>, int}
     */
    public function computeSpeedPage(?string $eventId, int $limit, int $offset): array
    {
        return $this->query->computeSpeedPage($eventId, $limit, $offset);
    }
}
