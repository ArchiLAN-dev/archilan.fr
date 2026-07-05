<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

interface CurrentWeeklyRunsQueryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function execute(?string $myUserId): array;
}
