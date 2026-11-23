<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 56.5: the organisers of a private run (owner or co-organiser, story 16.27) set how long a world should last
 * in it; the YAML test and the balance report measure each world against it. Null removes it.
 */
final readonly class SetRunTargetHours
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private ClockInterface $clock,
    ) {
    }

    public function set(string $runId, string $callerId, ?int $hours): SetRunTargetHoursOutcome
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return SetRunTargetHoursOutcome::NotFound;
        }
        $participant = $run->isOwnedBy($callerId) ? null : $this->participants->findByRunAndUser($runId, $callerId);
        if (!$run->isAdministeredBy($callerId, $participant instanceof RunParticipant && $participant->isCoOwner())) {
            return SetRunTargetHoursOutcome::Forbidden;
        }
        if (null !== $hours && ($hours < 1 || $hours > Run::MAX_TARGET_HOURS)) {
            return SetRunTargetHoursOutcome::Invalid;
        }

        $run->aimFor($hours, $this->clock->now());
        $this->runs->flush();

        return SetRunTargetHoursOutcome::Done;
    }
}
