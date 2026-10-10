<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Message\NotifySlotUnblockedJob;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 40.1: tells the player of an unblocked slot, and its co-players, that checks are reachable
 * again. Private runs only: there `SessionSlot.registrationId` holds the player's user id.
 */
#[AsMessageHandler]
final readonly class NotifySlotUnblockedJobHandler
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionSlotRepositoryInterface $slots,
        private SlotCoPlayerRepositoryInterface $coPlayers,
        private Notifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifySlotUnblockedJob $job): void
    {
        try {
            $this->notifyPlayers($job);
        } catch (\Throwable $e) {
            // A courtesy notification: never worth the failure transport, just log.
            $this->logger->error('session.slot_unblocked_notify_failed', [
                'sessionId' => $job->sessionId,
                'slotName' => $job->slotName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyPlayers(NotifySlotUnblockedJob $job): void
    {
        $run = $this->runs->findBySessionId($job->sessionId);
        $slot = $this->slots->findBySessionAndSlotName($job->sessionId, $job->slotName);
        if (null === $run || null === $slot) {
            return;
        }

        $recipients = [$slot->getRegistrationId()];
        $gameSlotId = $slot->getSlotId();
        if (null !== $gameSlotId) {
            foreach ($this->coPlayers->findBySlotIds([$gameSlotId]) as $coPlayer) {
                $recipients[] = $coPlayer->getUserId();
            }
        }

        foreach (array_unique($recipients) as $userId) {
            $this->notifier->notify($userId, NotifySlotUnblockedJob::NOTIFICATION_TYPE, [
                'runId' => $run->getId(),
                'runTitle' => $run->getTitle(),
                'slotName' => $job->slotName,
                'reachableNow' => $job->reachableNow,
            ] + (null !== $job->slotIndex ? ['slotIndex' => $job->slotIndex] : []));
        }
    }
}
