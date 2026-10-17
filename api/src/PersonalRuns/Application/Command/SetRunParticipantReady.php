<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\PersonalRuns\Application\Message\NotifyRunAllReadyJob;
use App\PersonalRuns\Application\Support\RunReadinessReader;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\PersonalRuns\Domain\Service\RunReadiness;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A player of a draft run says they are ready, or no longer are (story 16.23). Each player speaks for themselves
 * only, and only while the run is not launched. Advisory: the owner can launch whatever the count says.
 *
 * The owner hears of it when the last player becomes ready, unless that player is the owner.
 */
final readonly class SetRunParticipantReady
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunReadinessReader $readiness,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function set(string $runId, string $userId, bool $ready): SetRunParticipantReadyOutcome
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return SetRunParticipantReadyOutcome::NotFound;
        }
        $participant = $this->participants->findByRunAndUser($runId, $userId);
        if (!$participant instanceof RunParticipant) {
            return SetRunParticipantReadyOutcome::NotFound;
        }
        if ($run->isLockedForEditing()) {
            return SetRunParticipantReadyOutcome::Launched;
        }

        if (!$ready) {
            $participant->clearReady();
            $this->participants->flush();

            return SetRunParticipantReadyOutcome::Updated;
        }

        $all = $this->participants->findByRunId($runId);
        $eligible = $this->readiness->eligible($all);
        if (!isset($eligible[$userId])) {
            return SetRunParticipantReadyOutcome::NothingToPlay;
        }
        if ($participant->isReady()) {
            return SetRunParticipantReadyOutcome::Updated;
        }

        $participant->markReady($this->clock->now());
        $this->participants->flush();

        // Only on the change that completes the count: a player going back and forth on their own
        // status while others are missing says nothing, and the owner completing it needs no telling.
        $count = RunReadiness::count($all, $eligible);
        if ($count['ready'] === $count['total'] && !$run->isOwnedBy($userId)) {
            $this->messageBus->dispatch(new NotifyRunAllReadyJob($runId, $userId));
        }

        return SetRunParticipantReadyOutcome::Updated;
    }
}
