<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain;

interface WeeklyEntryRepositoryInterface
{
    public function findById(string $id): ?WeeklyEntry;

    public function findByExternalSessionId(string $externalSessionId): ?WeeklyEntry;

    public function countByRunAndUser(string $weeklyRunId, string $userId): int;

    /**
     * Returns entries that have an active external session (launched, goal not yet reached).
     *
     * @return list<WeeklyEntry>
     */
    public function findActiveEntriesForRun(string $weeklyRunId): array;

    public function save(WeeklyEntry $entry): void;

    public function remove(WeeklyEntry $entry): void;

    public function flush(): void;
}
