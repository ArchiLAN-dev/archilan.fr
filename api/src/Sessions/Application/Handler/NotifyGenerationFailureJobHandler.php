<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Message\NotifyGenerationFailureJob;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 9.41: routes an attributed generation failure to the people who can act on it -
 * each faulty slot's player (with the world's actionable message and a link to the run),
 * and the run owner (summary). Weekly-gen sessions never reach this handler (their crash
 * webhook is handled out-of-band, see OrchestratorWebhookController).
 *
 * Slot -> user resolution: for personal runs, SessionSlot.registrationId holds the user id
 * directly (see LaunchPersonalRunJobHandler); for event sessions it is a Registration id.
 */
#[AsMessageHandler]
final readonly class NotifyGenerationFailureJobHandler
{
    private const int MESSAGE_EXCERPT_MAX = 300;

    public function __construct(
        private SessionSlotRepositoryInterface $slots,
        private RunRepositoryInterface $runs,
        private RegistrationRepositoryInterface $registrations,
        private Notifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyGenerationFailureJob $job): void
    {
        try {
            $this->notifyRecipients($job);
        } catch (\Throwable $e) {
            // A notification is a side effect of an already-recorded crash: never let it
            // land in the failure transport loop, just log.
            $this->logger->error('session.generation_failure_notify_failed', [
                'sessionId' => $job->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyRecipients(NotifyGenerationFailureJob $job): void
    {
        $run = $this->runs->findBySessionId($job->sessionId);
        $runId = $run instanceof Run ? $run->getId() : null;
        $runTitle = $run instanceof Run ? $run->getTitle() : null;

        $slotUserIds = [];
        foreach ($this->slots->findBySessionId($job->sessionId) as $slot) {
            $userId = $this->resolveUserId($slot->getRegistrationId(), $run instanceof Run);
            if (null !== $userId) {
                $slotUserIds[$slot->getSlotName()] = $userId;
            }
        }

        // One notification per faulty player, whatever the number of findings (AC4).
        /** @var array<string, array{slotNames: list<string>, message: string}> $byUser */
        $byUser = [];
        $attributedSlotNames = [];
        foreach ($job->findings as $finding) {
            $slotName = $finding['slotName'];
            if (null === $slotName || !isset($slotUserIds[$slotName])) {
                continue;
            }
            if (!in_array($slotName, $attributedSlotNames, true)) {
                $attributedSlotNames[] = $slotName;
            }

            $userId = $slotUserIds[$slotName];
            if (!isset($byUser[$userId])) {
                $byUser[$userId] = ['slotNames' => [], 'message' => $finding['message']];
            }
            if (!in_array($slotName, $byUser[$userId]['slotNames'], true)) {
                $byUser[$userId]['slotNames'][] = $slotName;
            }
        }

        foreach ($byUser as $userId => $data) {
            $this->notifier->notify($userId, NotifyGenerationFailureJob::NOTIFICATION_TYPE, [
                'role' => 'player',
                'slotName' => $data['slotNames'][0],
                'slotNames' => $data['slotNames'],
                'message' => mb_substr($data['message'], 0, self::MESSAGE_EXCERPT_MAX),
                'runId' => $runId,
                'runTitle' => $runTitle,
            ]);
        }

        // Owner summary (personal runs only), skipped when the owner already got the
        // player-facing notification (AC2).
        if ($run instanceof Run && !isset($byUser[$run->getOwnerId()])) {
            $this->notifier->notify($run->getOwnerId(), NotifyGenerationFailureJob::NOTIFICATION_TYPE, [
                'role' => 'owner',
                'slotNames' => $attributedSlotNames,
                'runId' => $runId,
                'runTitle' => $runTitle,
            ]);
        }
    }

    private function resolveUserId(string $registrationId, bool $isPersonalRun): ?string
    {
        if ($isPersonalRun) {
            return $registrationId;
        }

        return $this->registrations->findById($registrationId)?->getUserId();
    }
}
