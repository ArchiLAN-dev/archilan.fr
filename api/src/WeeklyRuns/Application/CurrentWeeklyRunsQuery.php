<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

final readonly class CurrentWeeklyRunsQuery
{
    public function __construct(private CurrentWeeklyRunsQueryInterface $query)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(?string $myUserId): array
    {
        return $this->query->execute($myUserId);
    }
}
