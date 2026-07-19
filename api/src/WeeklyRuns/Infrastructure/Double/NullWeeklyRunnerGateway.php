<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure\Double;

use App\WeeklyRuns\Application\Port\WeeklyRunnerGatewayInterface;

final class NullWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    public function launchEntry(string $entryId, string $apworldHash, string $templateYaml, string $outputKey, array $serverOptions = [], ?string $joinPassword = null): array
    {
        return [
            'externalSessionId' => 'null-session-id',
            'connectionInfo' => [
                'host' => 'localhost',
                'port' => 38281,
                'password' => null,
            ],
            'bridgePort' => null,
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
