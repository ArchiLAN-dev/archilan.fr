<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;

final class SpyWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    /** @var list<array{entryId: string, apworldHash: string, templateYaml: string, outputKey: string}> */
    public array $launchCalls = [];

    public function reset(): void
    {
        $this->launchCalls = [];
    }

    public function launchEntry(string $entryId, string $apworldHash, string $templateYaml, string $outputKey): array
    {
        $this->launchCalls[] = [
            'entryId' => $entryId,
            'apworldHash' => $apworldHash,
            'templateYaml' => $templateYaml,
            'outputKey' => $outputKey,
        ];

        return [
            'externalSessionId' => 'spy-session-'.$entryId,
            'connectionInfo' => [
                'host' => 'runner.test',
                'port' => 38281,
                'password' => null,
            ],
            'bridgePort' => 5001,
        ];
    }

    public function terminate(string $externalSessionId): void
    {
    }

    public function getStats(string $externalSessionId): array
    {
        return [
            'checksTotal' => 0,
            'itemsTotal' => 0,
            'goalReachedAt' => null,
        ];
    }
}
