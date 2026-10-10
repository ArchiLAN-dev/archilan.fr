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

    /**
     * Story 43.19: records a nudge on an existing row only if none went out since $cooldownStart and the player did
     * not mute them - in one conditional write, so two senders at the same instant cannot both go through.
     *
     * @return bool whether this nudge was recorded
     */
    public function claimNudge(string $runId, string $recipientId, string $senderId, \DateTimeImmutable $now, \DateTimeImmutable $cooldownStart): bool;
}
