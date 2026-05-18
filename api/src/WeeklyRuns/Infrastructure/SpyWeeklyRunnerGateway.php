<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;

final class SpyWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    /** @var list<array{entryId: string, seed: string, apworldStorageKey: string, apworldDownloadUrl: string, playerName: string, yaml: string, archipelagoGameName: string}> */
    public array $launchCalls = [];

    public function reset(): void
    {
        $this->launchCalls = [];
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
        $this->launchCalls[] = [
            'entryId' => $weeklyEntryId,
            'seed' => $seed,
            'apworldStorageKey' => $apworldStorageKey,
            'apworldDownloadUrl' => $apworldDownloadUrl,
            'playerName' => $playerName,
            'yaml' => $yaml,
            'archipelagoGameName' => $archipelagoGameName,
        ];

        return [
            'externalSessionId' => 'spy-session-'.$weeklyEntryId,
            'connectionInfo' => [
                'host' => 'runner.test',
                'port' => 38281,
                'password' => null,
            ],
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
