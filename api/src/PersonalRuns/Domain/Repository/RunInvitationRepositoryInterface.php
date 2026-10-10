<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Repository;

use App\PersonalRuns\Domain\Entity\RunInvitation;

interface RunInvitationRepositoryInterface
{
    public function findById(string $id): ?RunInvitation;

    public function findByRunAndInvitee(string $runId, string $inviteeId): ?RunInvitation;

    /** @return list<RunInvitation> every invitation of the run, the latest first */
    public function findByRunId(string $runId): array;

    /** @return list<RunInvitation> the invitee's pending invitations, the latest first */
    public function findPendingForInvitee(string $inviteeId): array;

    /** Invitations of the run sent (or sent again) since the given instant - the daily cap. */
    public function countSentSince(string $runId, \DateTimeImmutable $since): int;

    public function save(RunInvitation $invitation): void;

    public function deleteByRunId(string $runId): void;

    /** Story 43.19: closes the run's invitations still waiting for an answer (flushed). */
    public function closePendingForRun(string $runId, \DateTimeImmutable $now): void;

    public function flush(): void;
}
