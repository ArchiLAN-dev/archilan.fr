<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface AdminWeeklyRunGameListQueryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array;
}
