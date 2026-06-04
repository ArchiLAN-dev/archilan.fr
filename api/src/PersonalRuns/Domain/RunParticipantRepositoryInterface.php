<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain;

interface RunParticipantRepositoryInterface
{
    /**
     * @return list<RunParticipant>
     */
    public function findByRunId(string $runId): array;

    public function findByRunAndUser(string $runId, string $userId): ?RunParticipant;

    public function countByRunId(string $runId): int;

    public function save(RunParticipant $participant): void;

    public function deleteByRunId(string $runId): void;

    public function flush(): void;
}
