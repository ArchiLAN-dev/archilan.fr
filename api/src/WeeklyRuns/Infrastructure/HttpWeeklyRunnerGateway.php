<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    public function __construct(
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

    public function launchEntry(
        string $weeklyEntryId,
        string $seed,
        string $apworldStorageKey,
        string $apworldDownloadUrl,
        string $playerName,
        string $yaml,
        string $archipelagoGameName,
    ): array {
        $slot = [
            'slotName' => $playerName,
            'apworldStorageKey' => $apworldStorageKey,
            'playerYaml' => $yaml,
        ];
        if ('' !== $apworldDownloadUrl) {
            $slot['apworldDownloadUrl'] = $apworldDownloadUrl;
        }

        $data = $this->post(
            "/sessions/{$weeklyEntryId}/generate-and-launch",
            [
                'seed' => $seed,
                'slots' => [$slot],
                'bridgeConfig' => [
                    'RUN_ID' => $weeklyEntryId,
                    'SYMFONY_INTERNAL_URL' => $this->symfonyInternalUrl,
                    'MERCURE_HUB_URL' => $this->mercureHubUrl,
                    'CENTRAL_API_SECRET' => $this->centralApiSecret,
                    'BRIDGE_INTERNAL_TOKEN' => $this->bridgeInternalToken,
                    'SLOT_NAMES' => json_encode([['name' => $playerName, 'game' => $archipelagoGameName]], \JSON_THROW_ON_ERROR),
                ],
            ],
            // Must exceed runner's GENERATION_TIMEOUT (default 300s) plus container startup time.
            timeout: 360,
        );

        if (isset($data['error'])) {
            $errorMsg = is_string($data['error']) ? $data['error'] : 'unknown';
            $details = isset($data['details']) ? (is_string($data['details']) ? $data['details'] : json_encode($data['details'])) : null;
            $this->logger->error('weekly_runner.launch_failed', ['error' => $errorMsg, 'details' => $details]);
            throw new \RuntimeException('Runner launchEntry failed: '.$errorMsg.($details ? ' | '.$details : ''));
        }

        $portRaw = $data['containerPort'] ?? null;
        if (!is_int($portRaw) && !is_string($portRaw) && !is_float($portRaw)) {
            throw new \RuntimeException('Runner launchEntry: missing or invalid containerPort in response');
        }
        $port = (int) $portRaw;

        $passwordRaw = $data['serverPassword'] ?? null;
        $password = is_string($passwordRaw) ? $passwordRaw : null;

        return [
            'externalSessionId' => $weeklyEntryId,
            'connectionInfo' => [
                'host' => $this->runnerPublicHost,
                'port' => $port,
                'password' => $password,
            ],
        ];
    }

    public function terminate(string $externalSessionId): void
    {
        $result = $this->delete("/sessions/{$externalSessionId}");
        if (isset($result['error'])) {
            $errorMsg = is_string($result['error']) ? $result['error'] : 'unknown';
            throw new \RuntimeException('Runner terminate failed: '.$errorMsg);
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

    /** @return array<string, mixed> */
    private function delete(string $path): array
    {
        try {
            $response = $this->httpClient->request('DELETE', $this->url($path), [
                'headers' => ['x-api-key' => $this->runnerApiKey],
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
