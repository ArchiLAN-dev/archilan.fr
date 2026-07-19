<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Command;

/**
 * Result of {@see LaunchWeeklyEntry::execute}: the launched entry id, the orchestrator session id, and the
 * connection info the player uses to join. `connectionInfo` is the gateway's contract shape, passed through
 * verbatim.
 */
final readonly class LaunchedEntry
{
    /**
     * @param array{host: string, port: int, password: string|null} $connectionInfo
     */
    public function __construct(
        public string $entryId,
        public string $externalSessionId,
        public array $connectionInfo,
    ) {
    }
}
