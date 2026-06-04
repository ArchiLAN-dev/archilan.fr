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
        private string $minioApworldsBucket,
        private int $minioPresignTtl,
    ) {
    }

    public function generate(
        string $weeklyRunId,
        string $apworldStorageKey,
        string $templateYaml,
        string $seed,
    ): string {
        $presignedUrl = $this->minioStorage->presignedUrl(
            $this->minioApworldsBucket,
            $apworldStorageKey,
            $this->minioPresignTtl,
        );

        $contents = $this->httpClient->request('GET', $presignedUrl)->getContent(true);
        $filename = basename($apworldStorageKey);

        $result = $this->client->apworlds()->upload($contents, $filename);

        return $result->hash;
    }
}
