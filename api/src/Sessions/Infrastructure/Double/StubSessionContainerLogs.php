<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Double;

use App\Sessions\Application\Port\SessionContainerLogsInterface;
use App\Sessions\Application\Support\SessionContainerLogs;

/**
 * Test double: every container exists and says which one it is, with the tail it was asked for.
 */
final readonly class StubSessionContainerLogs implements SessionContainerLogsInterface
{
    public function read(string $sessionId, string $container, int $tail): SessionContainerLogs
    {
        return new SessionContainerLogs(
            sprintf('%s-%s', $container, $sessionId),
            true,
            sprintf("logs of %s (last %d lines)\n", $container, $tail),
        );
    }
}
