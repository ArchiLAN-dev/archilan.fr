<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\Shared\Infrastructure\MinioStorageInterface;
use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use Archilan\OrchestratorClient\OrchestratorClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OrchestratorWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    private const LAUNCH_POLL_INTERVAL_MS = 2_000;
    private const LAUNCH_TIMEOUT_S = 60;

    public function __construct(
        private OrchestratorClient $client,
        private MinioStorageInterface $minioStorage,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $orchestrateurBaseUrl,
        private string $orchestrateurApiKey,
        private string $runnerPublicHost,
        private string $minioSessionsBucket,
    ) {
    }

    public function launchEntry(string $entryId, string $apworldHash, string $templateYaml, string $outputKey, array $serverOptions = [], ?string $joinPassword = null): array
    {
        $adminPassword = bin2hex(random_bytes(16));
        // The join password comes from the resolved config when set, else a random one.
        $serverPassword = (null !== $joinPassword && '' !== $joinPassword) ? $joinPassword : bin2hex(random_bytes(8));

        // 1. Configure the entry session: uploads the template YAML + manifest to MinIO so
        //    the orchestrator can stage /data/yamls + /data/worlds (needed for reachability).
        $this->configureSession($entryId, $apworldHash, $templateYaml);

        // 2. Download the run's pre-generated world from MinIO (zero regeneration).
        $output = $this->minioStorage->download($this->minioSessionsBucket, $outputKey);

        // 3. Inject it into the session volume and launch — no generation is run.
        $this->client->sessions()->launchFromFile($entryId, $output, basename($outputKey), $adminPassword, $serverPassword, $serverOptions);
        $this->logger->info('weekly_entry.launch_from_file.triggered', ['entryId' => $entryId]);

        // 4. Poll until running and get connection info.
        $session = $this->pollUntilStatus($entryId, 'running', self::LAUNCH_TIMEOUT_S, self::LAUNCH_POLL_INTERVAL_MS);

        $apPort = $session->apPort;
        if (null === $apPort) {
            throw new \RuntimeException('Orchestrateur did not return apPort after launch');
        }

        return [
            'externalSessionId' => $entryId,
            'connectionInfo' => [
                'host' => $this->runnerPublicHost,
                'port' => $apPort,
                'password' => $session->serverPassword,
            ],
            'bridgePort' => $session->bridgePort,
        ];
    }

    public function terminate(string $externalSessionId): void
    {
        try {
            $this->client->sessions()->delete($externalSessionId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Terminate failed: '.$e->getMessage(), previous: $e);
        }
    }

    public function getStats(string $externalSessionId): array
    {
        throw new \RuntimeException('getStats not yet implemented for OrchestratorWeeklyRunnerGateway');
    }

    private function configureSession(string $entryId, string $apworldHash, string $templateYaml): void
    {
        $url = rtrim($this->orchestrateurBaseUrl, '/')."/sessions/{$entryId}/configure";
        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'slots' => [
                    ['apworldHash' => $apworldHash, 'playerYaml' => $templateYaml],
                ],
            ],
            'headers' => ['Authorization' => 'Bearer '.$this->orchestrateurApiKey],
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('Configure session failed (HTTP %d): %s', $response->getStatusCode(), $response->getContent(false)));
        }
    }

    private function pollUntilStatus(
        string $entryId,
        string $expectedStatus,
        int $timeoutSeconds,
        int $intervalMs,
    ): \Archilan\OrchestratorClient\Sessions\Response\SessionResponse {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $session = $this->client->sessions()->get($entryId);

            if ($session->status === $expectedStatus) {
                return $session;
            }

            if (str_contains($session->status, 'failed') || str_contains($session->status, 'crashed')) {
                throw new \RuntimeException(sprintf('Session %s entered failed state "%s" while waiting for "%s"', $entryId, $session->status, $expectedStatus));
            }

            usleep($intervalMs * 1_000);
        }

        throw new \RuntimeException(sprintf('Timed out after %ds waiting for session %s to reach "%s"', $timeoutSeconds, $entryId, $expectedStatus));
    }
}
