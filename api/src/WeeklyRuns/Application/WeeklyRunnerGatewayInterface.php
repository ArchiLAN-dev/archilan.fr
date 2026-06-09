<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunnerGatewayInterface
{
    /**
     * Launch a session for a weekly entry by reusing the run's pre-generated world.
     * The entry session is first configured (uploads the template YAML + manifest so the
     * orchestrator can stage /data/yamls + /data/worlds for reachability), then the
     * pre-generated multidata is downloaded from MinIO ($outputKey) and injected with
     * launch-from-file. Performs no Archipelago generation.
     *
     * @param array<string, scalar> $serverOptions resolved server_options for this entry (epic 27);
     *                                             the orchestrator maps them to ArchipelagoServer flags
     * @param ?string               $joinPassword  configured join password (null → gateway generates one)
     *
     * @return array{externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}, bridgePort: int|null}
     */
    public function launchEntry(string $entryId, string $apworldHash, string $templateYaml, string $outputKey, array $serverOptions = [], ?string $joinPassword = null): array;

    public function terminate(string $externalSessionId): void;

    /**
     * @return array{checksTotal: int, itemsTotal: int, goalReachedAt: string|null}
     */
    public function getStats(string $externalSessionId): array;
}
