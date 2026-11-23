<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 16.27: the owner of a private run makes a participant co-organiser, or takes it back. Only the owner does,
 * never a co-organiser; each change is logged (who, to whom, when) since the role opens the server's admin password.
 */
final readonly class SetRunCoOwner
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function set(string $runId, string $callerId, string $participantId, bool $coOwner): SetRunCoOwnerOutcome
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return SetRunCoOwnerOutcome::RunNotFound;
        }
        if (!$run->isOwnedBy($callerId)) {
            return SetRunCoOwnerOutcome::Forbidden;
        }
        if ($run->isOwnedBy($participantId)) {
            return SetRunCoOwnerOutcome::IsOwner;
        }
        if ($run->isTerminal()) {
            return SetRunCoOwnerOutcome::RunTerminal;
        }
        $participant = $this->participants->findByRunAndUser($runId, $participantId);
        if (!$participant instanceof RunParticipant) {
            return SetRunCoOwnerOutcome::ParticipantNotFound;
        }

        $now = $this->clock->now();
        if ($coOwner === $participant->isCoOwner()) {
            return SetRunCoOwnerOutcome::Done;
        }
        $coOwner ? $participant->makeCoOwner($now) : $participant->revokeCoOwner();
        $this->participants->flush();

        $this->logger->info($coOwner ? 'Run co-owner named.' : 'Run co-owner revoked.', [
            'runId' => $runId,
            'ownerId' => $callerId,
            'participantId' => $participantId,
            'at' => $now->format(\DateTimeInterface::ATOM),
        ]);

        return SetRunCoOwnerOutcome::Done;
    }
}
