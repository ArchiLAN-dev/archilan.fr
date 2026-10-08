<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure\Adapter;

use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use App\WeeklyRuns\Application\Port\WeeklyRunnerGatewayInterface;
use Archilan\OrchestratorClient\Exception\SessionNotFoundException;
use Archilan\OrchestratorClient\OrchestratorClient;
use Archilan\OrchestratorClient\Sessions\Response\SessionResponse;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OrchestratorWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    // Story 23.15: the orchestrateur gives a launch 120 s (LAUNCH_TIMEOUT) and a heavy world does take
    // more than a minute; waiting less made the API give up on a launch the orchestrateur then finished.
    private const int LAUNCH_TIMEOUT_S = 150;
    /** Orchestrateur states of a launch still under way, worth waiting for rather than relaunching. */
    private const array IN_PROGRESS_STATUSES = ['generating', 'launching'];

    public function __construct(
        private OrchestratorClient $client,
        private MinioStorageInterface $minioStorage,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $orchestrateurBaseUrl,
        private string $orchestrateurApiKey,
        private string $runnerPublicHost,
        private string $minioSessionsBucket,
        private int $launchPollIntervalMs = 2_000,
    ) {
    }

    public function launchEntry(string $entryId, string $apworldHash, string $templateYaml, string $outputKey, array $serverOptions = [], ?string $joinPassword = null): array
    {
        $adminPassword = bin2hex(random_bytes(16));
        // The join password comes from the resolved config when set, else a random one.
        $serverPassword = (null !== $joinPassword && '' !== $joinPassword) ? $joinPassword : bin2hex(random_bytes(8));

        // Story 23.15: a previous click may have launched this entry already (the API gave up before the
        // orchestrateur finished). Its session would refuse a new configure/launch with a 409 forever, so
        // adopt it instead - same template, same generated world, nothing to redo.
        $session = $this->adoptExisting($entryId);

        if (!$session instanceof SessionResponse) {
            // 1. Configure the entry session: uploads the template YAML + manifest to MinIO so
            //    the orchestrator can stage /data/yamls + /data/worlds (needed for reachability).
            $this->configureSession($entryId, $apworldHash, $templateYaml);

            // 2. Download the run's pre-generated world from MinIO (zero regeneration).
            $output = $this->minioStorage->download($this->minioSessionsBucket, $outputKey);

            // 3. Inject it into the session volume and launch - no generation is run.
            $this->client->sessions()->launchFromFile($entryId, $output, basename($outputKey), $adminPassword, $serverPassword, $serverOptions);
            $this->logger->info('weekly_entry.launch_from_file.triggered', ['entryId' => $entryId]);

            // 4. Poll until running and get connection info.
            $session = $this->pollUntilStatus($entryId, 'running', self::LAUNCH_TIMEOUT_S, $this->launchPollIntervalMs);
        }

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

    /**
     * The entry's orchestrateur session when it is running already (or about to), null when a launch
     * must happen: no session, or one stopped, idle, crashed or never launched.
     */
    private function adoptExisting(string $entryId): ?SessionResponse
    {
        try {
            $session = $this->client->sessions()->get($entryId);
        } catch (SessionNotFoundException) {
            return null;
        }

        if (in_array($session->status, self::IN_PROGRESS_STATUSES, true)) {
            $this->logger->info('weekly_entry.launch.awaiting_existing', ['entryId' => $entryId, 'status' => $session->status]);
            $session = $this->pollUntilStatus($entryId, 'running', self::LAUNCH_TIMEOUT_S, $this->launchPollIntervalMs);
        }

        if ('running' !== $session->status) {
            return null;
        }

        $this->logger->info('weekly_entry.launch.adopted_existing', ['entryId' => $entryId]);

        return $session;
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
    ): SessionResponse {
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
