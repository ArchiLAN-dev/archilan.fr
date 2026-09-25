<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\GameSelection\Application\Message\ReportDefaultYamlFailureJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Message\NotifyGenerationFailureJob;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends a real generation failure to the apworld health (story 38.4): for each slot the failure was
 * attributed to (story 9.40), the slot's YAML and apworld go to `ReportDefaultYamlFailure`, which alone
 * decides whether the apworld is at fault.
 *
 * A second handler of the crash notification job: it is dispatched once the crash is committed, and
 * carries the attributed findings. A `SessionSlot` holds neither YAML nor hash, so they are read back:
 * - personal run: `SessionSlot.registrationId` is the player's user id, the slot is on their
 *   `RunParticipant`, with the apworld it was generated with;
 * - event: `SessionSlot.registrationId` is the registration, and an event generates every slot with
 *   the apworld its game serves, so no hash is sent.
 * Weekly runs are not concerned: they are generated from an admin template, not from player slots, and
 * their failures do not come through this job. An unattributed failure accuses nothing.
 */
#[AsMessageHandler]
final readonly class ReportGenerationFailureToApworldHealthHandler
{
    public function __construct(
        private SessionSlotRepositoryInterface $slots,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RegistrationRepositoryInterface $registrations,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyGenerationFailureJob $job): void
    {
        try {
            $this->report($job);
        } catch (\Throwable $e) {
            // A side effect of an already-recorded crash: log, never loop in the failure transport.
            $this->logger->error('session.generation_failure_health_report_failed', ['sessionId' => $job->sessionId, 'error' => $e->getMessage()]);
        }
    }

    private function report(NotifyGenerationFailureJob $job): void
    {
        /** @var array<string, SessionSlot> $slotsByName */
        $slotsByName = [];
        foreach ($this->slots->findBySessionId($job->sessionId) as $slot) {
            $slotsByName[$slot->getSlotName()] = $slot;
        }

        $run = $this->runs->findBySessionId($job->sessionId);
        $reported = [];
        foreach ($job->findings as $finding) {
            $slot = null !== $finding['slotName'] ? ($slotsByName[$finding['slotName']] ?? null) : null;
            $slotId = $slot?->getSlotId();
            if (null === $slot || null === $slotId || '' === $slot->getGameId() || isset($reported[$slotId])) {
                continue;
            }

            $report = $run instanceof Run
                ? $this->personalRunReport($run, $slot, $slotId, $finding['message'])
                : $this->eventReport($slot, $slotId, $finding['message']);
            if (null !== $report) {
                $reported[$slotId] = true;
                $this->messageBus->dispatch($report);
            }
        }
    }

    private function personalRunReport(Run $run, SessionSlot $slot, string $slotId, string $error): ?ReportDefaultYamlFailureJob
    {
        $runSlot = $this->participants->findByRunAndUser($run->getId(), $slot->getRegistrationId())?->getSlot($slotId);
        if (null === $runSlot) {
            return null;
        }

        return new ReportDefaultYamlFailureJob($slot->getGameId(), $runSlot['apworldHash'] ?? null, $runSlot['playerYaml'] ?? '', $error);
    }

    private function eventReport(SessionSlot $slot, string $slotId, string $error): ?ReportDefaultYamlFailureJob
    {
        $registrationSlot = $this->registrations->findById($slot->getRegistrationId())?->getSlot($slotId);
        if (null === $registrationSlot) {
            return null;
        }

        return new ReportDefaultYamlFailureJob($slot->getGameId(), null, $registrationSlot['playerYaml'] ?? '', $error);
    }
}
