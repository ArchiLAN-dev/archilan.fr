<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Application\Port\RunnerCallbackClientInterface;
use App\Sessions\Application\Port\SessionSaveStorageInterface;
use App\Shared\Application\Support\BridgeEndpoint;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Records where a finished game's files are kept, and the slots' final state. Story 17.37: the save is the one the
 * orchestrateur copies to durable storage when the game stops (it did so before this job runs: the stop call is
 * synchronous); the spoiler is in the generated output archive. The job used to copy both from a local workspace the
 * API never had - it found nothing, every time.
 */
#[AsMessageHandler]
final readonly class ArchiveRunJobHandler
{
    public function __construct(
        private RunnerCallbackClientInterface $callbackClient,
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private SessionSaveStorageInterface $saves,
        private string $runnerId,
    ) {
    }

    public function __invoke(ArchiveRunJob $job): void
    {
        $sessionId = $job->sessionId;

        $this->logger->info('runner.archive_job.started', [
            'session_id' => $sessionId,
            'runner_id' => $this->runnerId,
        ]);

        $archivedSavePath = $this->saves->locate($sessionId);

        $slots = $this->fetchBridgeState($job->bridgePort, $sessionId);

        $this->callbackClient->sendCallback($sessionId, [
            'status' => 'archived',
            'archived_save_path' => $archivedSavePath,
            // The spoiler is read out of the generated output archive, whose key the session already holds.
            'archived_spoiler_path' => null,
            'slots' => $slots,
        ]);

        $this->logger->info('runner.archive_job.done', [
            'session_id' => $sessionId,
            'runner_id' => $this->runnerId,
            'save_archived' => null !== $archivedSavePath,
            'slot_count' => count($slots),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function fetchBridgeState(int $bridgePort, string $sessionId): array
    {
        if ($bridgePort <= 0) {
            return [];
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                BridgeEndpoint::url($sessionId, '/state'),
                ['timeout' => 3],
            );
            $data = $response->toArray();
            $rawSlots = $data['slots'] ?? [];

            if (!is_array($rawSlots)) {
                return [];
            }

            $result = [];
            foreach ($rawSlots as $slotData) {
                if (!is_array($slotData)) {
                    continue;
                }

                $result[] = [
                    'slot_name' => is_string($slotData['slot_name'] ?? null) ? $slotData['slot_name'] : '',
                    'checks_done' => is_int($slotData['checks_done'] ?? null) ? $slotData['checks_done'] : 0,
                    'items_received' => is_int($slotData['items_received'] ?? null) ? $slotData['items_received'] : 0,
                    'goal_reached_at' => is_string($slotData['goal_reached_at'] ?? null) ? $slotData['goal_reached_at'] : null,
                ];
            }

            return $result;
        } catch (\Throwable) {
            $this->logger->warning('runner.archive_job.bridge_state_failed', ['session_id' => $sessionId]);

            return [];
        }
    }
}
