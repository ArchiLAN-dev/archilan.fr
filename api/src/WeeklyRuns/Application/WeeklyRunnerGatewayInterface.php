<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunnerGatewayInterface
{
    /**
     * Launch a session for a weekly entry by reusing the run's pre-generated world
     * (downloaded from MinIO via $outputKey and injected with launch-from-file).
     * Performs no Archipelago generation.
     *
     * @return array{externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}, bridgePort: int|null}
     */
    public function launchEntry(string $entryId, string $outputKey): array;

    public function terminate(string $externalSessionId): void;

    /**
     * @return array{checksTotal: int, itemsTotal: int, goalReachedAt: string|null}
     */
    public function getStats(string $externalSessionId): array;
}
