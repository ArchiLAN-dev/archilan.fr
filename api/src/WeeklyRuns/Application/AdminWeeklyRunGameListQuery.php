<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

final readonly class AdminWeeklyRunGameListQuery
{
    public function __construct(private AdminWeeklyRunGameListQueryInterface $query)
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
