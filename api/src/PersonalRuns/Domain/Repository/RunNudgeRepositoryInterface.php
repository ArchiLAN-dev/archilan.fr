<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Repository;

use App\PersonalRuns\Domain\Entity\RunNudge;

interface RunNudgeRepositoryInterface
{
    public function find(string $runId, string $recipientId): ?RunNudge;

    /** @return list<RunNudge> */
    public function findByRunId(string $runId): array;

    /** Persists and flushes. */
    public function save(RunNudge $nudge): void;

    public function deleteByRunId(string $runId): void;
}
