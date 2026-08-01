<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\PersonalRuns\Application\Message\RunSlotPreflightJob;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Support\GenerationFailureParser;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Story 9.42: runs one slot preflight to completion without ever sleeping - the handler
 * polls once per invocation and re-dispatches itself with a delay while the orchestrator
 * job is pending. Stale results (yaml edited or slot removed meanwhile) are dropped.
 */
#[AsMessageHandler]
final readonly class RunSlotPreflightJobHandler
{
    private const int POLL_DELAY_MS = 5000;
    /** ~7 minutes of polling: past the orchestrator's own 5-minute preflight timeout. */
    private const int MAX_POLLS = 84;

    public function __construct(
        private RunParticipantRepositoryInterface $participants,
        private RunnerGatewayInterface $runnerGateway,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunSlotPreflightJob $job): void
    {
        $participant = $this->participants->findByRunAndUser($job->runId, $job->userId);
        if (!$participant instanceof RunParticipant) {
            return;
        }

        $slot = $participant->getSlot($job->slotId);
        $yaml = is_string($slot['playerYaml'] ?? null) ? $slot['playerYaml'] : '';
        if (null === $slot || '' === $yaml || hash('sha256', $yaml) !== $job->yamlSha) {
            // Slot removed or yaml edited since the check was requested: the result would
            // describe a config that no longer exists.
            return;
        }

        if (null === $job->orchestratorJobId) {
            $this->start($job, $slot);

            return;
        }

        $this->poll($job, $participant);
    }

    /**
     * @param array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, preflight?: array{status: string, error: string, checkedAt: string, yamlSha: string}|null} $slot
     */
    private function start(RunSlotPreflightJob $job, array $slot): void
    {
        $yaml = is_string($slot['playerYaml'] ?? null) ? $slot['playerYaml'] : '';
        $apworldHash = is_string($slot['apworldHash'] ?? null) && '' !== $slot['apworldHash'] ? $slot['apworldHash'] : null;

        $orchestratorJobId = $this->runnerGateway->startSlotPreflight($yaml, $apworldHash);
        if (null === $orchestratorJobId) {
            $this->record($job, 'failed', 'Runner indisponible - relance le test plus tard.');

            return;
        }

        $this->messageBus->dispatch(
            new RunSlotPreflightJob($job->runId, $job->userId, $job->slotId, $job->yamlSha, $orchestratorJobId, 0),
            [new DelayStamp(self::POLL_DELAY_MS)],
        );
    }

    private function poll(RunSlotPreflightJob $job, RunParticipant $participant): void
    {
        $jobId = $job->orchestratorJobId;
        if (null === $jobId) {
            return;
        }

        $state = $this->runnerGateway->getSlotPreflight($jobId);

        if (null !== $state && 'pending' !== $state['status']) {
            $error = '';
            if ('failed' === $state['status']) {
                // Actionable one-liner: the generator's structured record when present,
                // else the parsed exception; never the raw multi-kilobyte excerpt
                // (stories 9.40/9.43).
                $error = GenerationFailureParser::summarize($state['error']);
            }
            $this->record($job, $state['status'], $error);

            return;
        }

        if ($job->polls >= self::MAX_POLLS) {
            $this->record($job, 'failed', null === $state
                ? 'Résultat du test indisponible (orchestrateur redémarré ?).'
                : 'Le test de génération a dépassé le délai maximal.');

            return;
        }

        $this->messageBus->dispatch(
            new RunSlotPreflightJob($job->runId, $job->userId, $job->slotId, $job->yamlSha, $jobId, $job->polls + 1),
            [new DelayStamp(self::POLL_DELAY_MS)],
        );
    }

    private function record(RunSlotPreflightJob $job, string $status, string $error): void
    {
        // Re-read: the participant may have changed while the container ran.
        $participant = $this->participants->findByRunAndUser($job->runId, $job->userId);
        if (!$participant instanceof RunParticipant) {
            return;
        }
        $slot = $participant->getSlot($job->slotId);
        $yaml = is_string($slot['playerYaml'] ?? null) ? $slot['playerYaml'] : '';
        if (null === $slot || hash('sha256', $yaml) !== $job->yamlSha) {
            return;
        }

        if ($participant->recordSlotPreflight($job->slotId, $status, $error, $job->yamlSha, $this->clock->now())) {
            $this->participants->flush();
            $this->logger->info('personal_run.slot_preflight_recorded', [
                'runId' => $job->runId,
                'slotId' => $job->slotId,
                'status' => $status,
            ]);
        }
    }
}
