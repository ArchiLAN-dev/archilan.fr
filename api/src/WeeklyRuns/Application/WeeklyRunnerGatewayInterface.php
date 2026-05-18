<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunnerGatewayInterface
{
    /**
     * @return array{externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}}
     */
    public function launchEntry(
        string $weeklyEntryId,
        string $seed,
        string $apworldStorageKey,
        string $apworldDownloadUrl,
        string $playerName,
        string $yaml,
        string $archipelagoGameName,
    ): array;

    public function terminate(string $externalSessionId): void;

    /**
     * @return array{checksTotal: int, itemsTotal: int, goalReachedAt: string|null}
     */
    public function getStats(string $externalSessionId): array;
}
