<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

use App\Sessions\Application\Exception\SessionLogsUnavailableException;
use App\Sessions\Application\Support\SessionContainerLogs;

/**
 * Reads the logs of a session's container through the orchestrator, the only one that can see them (story 17.33).
 */
interface SessionContainerLogsInterface
{
    /**
     * @param 'ap'|'bridge'|'gen' $container
     *
     * @throws SessionLogsUnavailableException when the orchestrator cannot answer
     */
    public function read(string $sessionId, string $container, int $tail): SessionContainerLogs;
}
