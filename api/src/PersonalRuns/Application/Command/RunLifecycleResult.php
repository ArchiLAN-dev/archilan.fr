<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

/**
 * The acknowledged outcome of a personal-run lifecycle transition (start/stop/finish/markRunning/
 * markStopped): the run id and its new status. A typed record instead of an `array{runId, status}` shape
 * (epic 35 Stage 2). Colocated with the commands that return it (Application/Command/).
 */
final readonly class RunLifecycleResult
{
    public function __construct(
        public string $runId,
        public string $status,
    ) {
    }
}
