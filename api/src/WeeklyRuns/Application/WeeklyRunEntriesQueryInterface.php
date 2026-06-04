<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunEntriesQueryInterface
{
    /**
     * @return list<array{
     *     userId: string,
     *     displayName: string|null,
     *     attemptNumber: int,
     *     externalSessionId: string|null,
     *     launchedAt: string|null,
     *     goalReachedAt: string|null,
     *     completionTimeSeconds: int|null,
     *     checksTotal: int|null,
     *     itemsTotal: int|null,
     * }>|null
     */
    public function findByRunId(string $weeklyRunId): ?array;
}
