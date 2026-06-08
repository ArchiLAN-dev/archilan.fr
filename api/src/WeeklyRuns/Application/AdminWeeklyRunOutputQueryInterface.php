<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface AdminWeeklyRunOutputQueryInterface
{
    /**
     * Returns the MinIO output key of a weekly run's generated multidata, or null if the
     * run does not exist or has not been generated yet.
     */
    public function findOutputKey(string $weeklyRunId): ?string;
}
