<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunnerGatewayInterface
{
    /**
     * Launch an Archipelago server from a pre-generated seed file.
     *
     * @return array{externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}, bridgePort: int|null}
     */
    public function launchFromSeed(
        string $weeklyEntryId,
        string $seedFilePath,
    ): array;

    public function terminate(string $externalSessionId): void;

    /**
     * @return array{checksTotal: int, itemsTotal: int, goalReachedAt: string|null}
     */
    public function getStats(string $externalSessionId): array;
}
