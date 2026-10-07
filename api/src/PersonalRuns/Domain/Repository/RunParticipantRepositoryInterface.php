<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Repository;

use App\PersonalRuns\Domain\Entity\RunParticipant;

interface RunParticipantRepositoryInterface
{
    /**
     * @return list<RunParticipant>
     */
    public function findByRunId(string $runId): array;

    public function findByRunAndUser(string $runId, string $userId): ?RunParticipant;

    /**
     * Every run a member takes part in, owned or joined (story 36.9): one query for the admin sheet.
     *
     * @return list<RunParticipant>
     */
    public function findByUserId(string $userId): array;

    public function countByRunId(string $runId): int;

    public function save(RunParticipant $participant): void;

    public function deleteByRunId(string $runId): void;

    public function flush(): void;
}
