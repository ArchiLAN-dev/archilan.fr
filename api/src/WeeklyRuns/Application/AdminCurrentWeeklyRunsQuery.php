<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

final readonly class AdminCurrentWeeklyRunsQuery
{
    public function __construct(private AdminCurrentWeeklyRunsQueryInterface $query)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        return $this->query->execute();
    }
}
