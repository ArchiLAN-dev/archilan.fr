<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;

final class NullWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    public function launchEntry(
        string $weeklyEntryId,
        string $seed,
        string $apworldStorageKey,
        string $apworldDownloadUrl,
        string $playerName,
        string $yaml,
    ): array {
        return [
            'externalSessionId' => 'null-session-id',
            'connectionInfo' => [
                'host' => 'localhost',
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
