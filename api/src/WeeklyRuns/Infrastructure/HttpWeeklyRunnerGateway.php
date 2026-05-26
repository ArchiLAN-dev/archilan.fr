<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use Archilan\OrchestratorClient\OrchestratorClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    public function __construct(
        private OrchestratorClient $client,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $runnerBaseUrl,
        private string $runnerApiKey,
        private string $runnerPublicHost,
        private string $symfonyInternalUrl,
        private string $mercureHubUrl,
        private string $centralApiSecret,
        private string $bridgeInternalToken,
    ) {
    }

    public function launchFromSeed(
        string $weeklyEntryId,
        string $seedFilePath,
    ): array {
        // MIGRATION GAP: launchFromSeed is incompatible with the OrchestratorClient API.
        // Old API: sync, sends server-side outputFile path + bridgeConfig env vars, returns connection info.
        // New API: async (202), multipart file contents + adminPassword, no bridgeConfig injection.
        // Dedicated gap story needed before weekly runs can use OrchestratorClient.
        $data = $this->post(
            "/sessions/{$weeklyEntryId}/launch-from-file",
            [
                'outputFile' => $seedFilePath,
                'bridgeConfig' => [
                    'RUN_ID' => $weeklyEntryId,
                    'SYMFONY_INTERNAL_URL' => $this->symfonyInternalUrl,
                    'MERCURE_HUB_URL' => $this->mercureHubUrl,
                    'CENTRAL_API_SECRET' => $this->centralApiSecret,
                    'BRIDGE_INTERNAL_TOKEN' => $this->bridgeInternalToken,
                ],
            ],
            timeout: 30,
        );

        if (isset($data['error'])) {
            $errorMsg = is_string($data['error']) ? $data['error'] : 'unknown';
            $details = isset($data['details']) ? (is_string($data['details']) ? $data['details'] : json_encode($data['details'])) : null;
            $this->logger->error('weekly_runner.launch_from_seed_failed', ['error' => $errorMsg, 'details' => $details]);
            throw new \RuntimeException('Runner launchFromSeed failed: '.$errorMsg.($details ? ' | '.$details : ''));
        }

        $portRaw = $data['containerPort'] ?? null;
        if (!is_int($portRaw) && !is_string($portRaw) && !is_float($portRaw)) {
            throw new \RuntimeException('Runner launchFromSeed: missing or invalid containerPort in response');
        }
        $port = (int) $portRaw;

        $passwordRaw = $data['serverPassword'] ?? null;
        $password = is_string($passwordRaw) ? $passwordRaw : null;

        $bridgePortRaw = $data['containerBridgePort'] ?? null;
        $bridgePort = (is_int($bridgePortRaw) || is_string($bridgePortRaw) || is_float($bridgePortRaw)) ? (int) $bridgePortRaw : null;

        return [
            'externalSessionId' => $weeklyEntryId,
            'connectionInfo' => [
                'host' => $this->runnerPublicHost,
                'port' => $port,
                'password' => $password,
            ],
            'bridgePort' => $bridgePort,
        ];
    }

    public function terminate(string $externalSessionId): void
    {
        try {
            $this->client->sessions()->delete($externalSessionId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Runner terminate failed: '.$e->getMessage(), previous: $e);
        }
    }

    public function getStats(string $externalSessionId): array
    {
        throw new \RuntimeException('Not yet implemented');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, int $timeout = 30): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->url($path), [
                'json' => $body,
                'headers' => ['x-api-key' => $this->runnerApiKey],
                'timeout' => $timeout,
            ]);

            return $this->toStringKeyedArray($response->toArray(false));
        } catch (\Throwable $e) {
            $this->logger->error('weekly_runner.request_failed', ['path' => $path, 'error' => $e->getMessage()]);

            return ['error' => 'runner_unavailable'];
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function toStringKeyedArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function url(string $path): string
    {
        return rtrim($this->runnerBaseUrl, '/').$path;
    }
}
