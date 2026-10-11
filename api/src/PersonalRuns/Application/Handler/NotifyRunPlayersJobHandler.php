<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\PersonalRuns\Application\Message\NotifyRunPlayersJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tells every player of a personal run that it started or that its recap is ready (story 43.28): the owner, each
 * participant, and the co-players of their slots (story 16.17), once each.
 */
#[AsMessageHandler]
final readonly class NotifyRunPlayersJobHandler
{
    public const string TYPE_STARTED = 'run_started';

    public const string TYPE_RECAP_READY = 'run_recap_ready';

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private SlotCoPlayerRepositoryInterface $coPlayers,
        private Notifier $notifier,
    ) {
    }

    public function __invoke(NotifyRunPlayersJob $job): void
    {
        $run = $this->runs->findBySessionId($job->sessionId);
        if (!$run instanceof Run) {
            return;
        }

        $type = NotifyRunPlayersJob::STARTED === $job->moment ? self::TYPE_STARTED : self::TYPE_RECAP_READY;
        $payload = ['runId' => $run->getId(), 'runTitle' => $run->getTitle()];
        foreach ($this->playersOf($run) as $userId) {
            $this->notifier->notify($userId, $type, $payload);
        }
    }

    /**
     * @return list<string>
     */
    private function playersOf(Run $run): array
    {
        $players = [$run->getOwnerId() => true];
        $slotIds = [];
        foreach ($this->participants->findByRunId($run->getId()) as $participant) {
            $players[$participant->getUserId()] = true;
            foreach ($participant->getGameSlots() as $slot) {
                $slotIds[] = $slot['slotId'];
            }
        }
        foreach ($this->coPlayers->findBySlotIds($slotIds) as $coPlayer) {
            $players[$coPlayer->getUserId()] = true;
        }

        return array_keys($players);
    }
}
