<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;

final class NullWeeklyRunnerGateway implements WeeklyRunnerGatewayInterface
{
    public function launchEntry(string $entryId, string $outputKey): array
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
