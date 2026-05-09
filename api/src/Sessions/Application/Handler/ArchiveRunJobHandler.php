<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Infrastructure\RunnerCallbackClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class ArchiveRunJobHandler
{
    public function __construct(
        private RunnerCallbackClient $callbackClient,
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private string $runnerId,
        private string $workspaceDir,
        private string $archiveDir,
    ) {
    }

    public function __invoke(ArchiveRunJob $job): void
    {
        $sessionId = $job->sessionId;

        $this->logger->info('runner.archive_job.started', [
            'session_id' => $sessionId,
            'runner_id' => $this->runnerId,
        ]);

        if (!is_dir($this->archiveDir)) {
            @mkdir($this->archiveDir, 0755, true);
        }

        $savesDir = $this->workspaceDir.'/'.$sessionId.'/saves';
        $outputDir = $this->workspaceDir.'/'.$sessionId.'/output';

        $archivedSavePath = null;
        $saveFiles = is_dir($savesDir) ? (glob($savesDir.'/*.apsave') ?: []) : [];
        if ([] !== $saveFiles) {
            $dest = $this->archiveDir.'/'.$sessionId.'.apsave';
            if (@copy($saveFiles[0], $dest)) {
                $archivedSavePath = $dest;
            }
        }

        $archivedSpoilerPath = null;
        $spoilerFiles = is_dir($outputDir) ? (glob($outputDir.'/*.archipelago') ?: []) : [];
        if ([] !== $spoilerFiles) {
            $dest = $this->archiveDir.'/'.$sessionId.'.archipelago';
            if (@copy($spoilerFiles[0], $dest)) {
                $archivedSpoilerPath = $dest;
            }
        }

        $slots = $this->fetchBridgeState($job->bridgePort, $sessionId);

        $this->callbackClient->sendCallback($sessionId, [
            'status' => 'archived',
            'archived_save_path' => $archivedSavePath,
            'archived_spoiler_path' => $archivedSpoilerPath,
            'slots' => $slots,
        ]);

        $this->logger->info('runner.archive_job.done', [
            'session_id' => $sessionId,
            'runner_id' => $this->runnerId,
            'save_archived' => null !== $archivedSavePath,
            'spoiler_archived' => null !== $archivedSpoilerPath,
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
                sprintf('http://localhost:%d/state', $bridgePort),
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
