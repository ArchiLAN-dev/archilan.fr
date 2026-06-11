<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\Shared\Infrastructure\MinioStorageInterface;
use App\WeeklyRuns\Application\WeeklyRunGeneratorInterface;
use Archilan\OrchestratorClient\OrchestratorClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OrchestratorWeeklyRunGenerator implements WeeklyRunGeneratorInterface
{
    public function __construct(
        private OrchestratorClient $client,
        private MinioStorageInterface $minioStorage,
        private HttpClientInterface $httpClient,
        private string $orchestrateurBaseUrl,
        private string $orchestrateurApiKey,
        private string $minioApworldsBucket,
        private int $minioPresignTtl,
    ) {
    }

    public function generate(
        string $weeklyRunId,
        string $apworldStorageKey,
        string $templateYaml,
        string $seed,
        array $generationOptions = [],
    ): void {
        $genSessionId = self::GENERATOR_SESSION_PREFIX.$weeklyRunId;
        // (constant defined on WeeklyRunGeneratorInterface, implemented here)

        // 1. Pull the apworld from MinIO and upload it to the orchestrator (→ content hash).
        $presignedUrl = $this->minioStorage->presignedUrl(
            $this->minioApworldsBucket,
            $apworldStorageKey,
            $this->minioPresignTtl,
        );
        $contents = $this->httpClient->request('GET', $presignedUrl)->getContent(true);
        $apworldHash = $this->client->apworlds()->upload($contents, basename($apworldStorageKey))->hash;

        // 2. Configure the generator session with the apworld + the fixed template YAML.
        $this->configureSession($genSessionId, $apworldHash, $templateYaml);

        // 3. Kick off generation with the run's deterministic seed - non-blocking.
        //    Completion arrives later via the `session.generated` webhook (outputKey),
        //    which marks the run launchable. We never poll here.
        $this->client->sessions()->generate($genSessionId, bin2hex(random_bytes(16)), $seed, $generationOptions);
    }

    private function configureSession(string $sessionId, string $apworldHash, string $templateYaml): void
    {
        $url = rtrim($this->orchestrateurBaseUrl, '/')."/sessions/{$sessionId}/configure";
        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'slots' => [
                    ['apworldHash' => $apworldHash, 'playerYaml' => $templateYaml],
                ],
            ],
            'headers' => ['Authorization' => 'Bearer '.$this->orchestrateurApiKey],
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('Configure generator session failed (HTTP %d): %s', $response->getStatusCode(), $response->getContent(false)));
        }
    }
}
