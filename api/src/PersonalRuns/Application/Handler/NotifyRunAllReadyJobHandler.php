<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Message\NotifyRunAllReadyJob;
use App\PersonalRuns\Application\Support\RunReadinessReader;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\PersonalRuns\Domain\Service\RunReadiness;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tells the owner that every player of their draft run is ready (story 16.23). The count is read again: a player
 * who changed their games in between, or a launch, keeps it quiet.
 */
#[AsMessageHandler]
final readonly class NotifyRunAllReadyJobHandler
{
    public const string NOTIFICATION_TYPE = 'run_all_ready';

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RunReadinessReader $readiness,
        private UserRepositoryInterface $users,
        private Notifier $notifier,
    ) {
    }

    public function __invoke(NotifyRunAllReadyJob $job): void
    {
        $run = $this->runs->findById($job->runId);
        if (!$run instanceof Run || $run->isLockedForEditing() || $run->isOwnedBy($job->lastUserId)) {
            return;
        }

        $participants = $this->participants->findByRunId($job->runId);
        $count = RunReadiness::count($participants, $this->readiness->eligible($participants));
        if (0 === $count['total'] || $count['ready'] !== $count['total']) {
            return;
        }

        $member = $this->users->findById($job->lastUserId);

        $this->notifier->notify($run->getOwnerId(), self::NOTIFICATION_TYPE, [
            'fromUserId' => $job->lastUserId,
            'playerName' => $member instanceof User ? $member->getDisplayName() : null,
            'runId' => $run->getId(),
            'runTitle' => $run->getTitle(),
            'readyCount' => $count['ready'],
        ]);
    }
}
