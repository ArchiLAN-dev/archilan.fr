<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

final readonly class AdminWeeklyRunOutputQuery
{
    public function __construct(private AdminWeeklyRunOutputQueryInterface $query)
    {
    }

    public function findOutputKey(string $weeklyRunId): ?string
    {
        return $this->query->findOutputKey($weeklyRunId);
    }
}
