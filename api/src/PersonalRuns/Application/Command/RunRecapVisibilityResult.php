<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

/**
 * Outcome of toggling a personal run's recap visibility (story 32.5): the run id and whether its recap
 * is now publicly shareable. A typed record rather than a raw array (epic 35 Stage 2).
 */
final readonly class RunRecapVisibilityResult
{
    public function __construct(
        public string $runId,
        public bool $recapPublic,
    ) {
    }
}
