<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Adapter;

use App\Sessions\Application\Exception\SessionLogsUnavailableException;
use App\Sessions\Application\Port\SessionContainerLogsInterface;
use App\Sessions\Application\Support\SessionContainerLogs;
use Archilan\OrchestratorClient\Exception\OrchestratorException;
use Archilan\OrchestratorClient\OrchestratorClient;

final readonly class OrchestratorSessionContainerLogs implements SessionContainerLogsInterface
{
    public function __construct(private OrchestratorClient $client)
    {
    }

    public function read(string $sessionId, string $container, int $tail): SessionContainerLogs
    {
        try {
            $logs = $this->client->sessions()->logs($sessionId, $container, $tail);
        } catch (OrchestratorException $e) {
            throw new SessionLogsUnavailableException($e->getMessage(), 0, $e);
        }

        return new SessionContainerLogs($logs->container, $logs->found, $logs->logs);
    }
}
