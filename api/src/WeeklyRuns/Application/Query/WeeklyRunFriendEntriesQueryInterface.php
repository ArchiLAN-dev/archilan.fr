<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

interface WeeklyRunFriendEntriesQueryInterface
{
    /**
     * The entries of the given members in a weekly run (story 43.8), every attempt.
     *
     * @param list<string> $userIds
     *
     * @return list<array{userId: string, launchedAt: string|null, goalReachedAt: string|null, completionTimeSeconds: int|null}>
     */
    public function entriesOf(string $weeklyRunId, array $userIds): array;
}
