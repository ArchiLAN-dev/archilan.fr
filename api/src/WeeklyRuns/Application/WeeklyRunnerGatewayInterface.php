<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunnerGatewayInterface
{
    /**
     * Configure, generate and launch a session for a weekly entry via the orchestrateur.
     *
     * @return array{externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}, bridgePort: int|null}
     */
    public function launchEntry(
        string $entryId,
        string $apworldHash,
        string $templateYaml,
        string $seed,
    ): array;

    public function terminate(string $externalSessionId): void;

    /**
     * @return array{checksTotal: int, itemsTotal: int, goalReachedAt: string|null}
     */
    public function getStats(string $externalSessionId): array;
}
