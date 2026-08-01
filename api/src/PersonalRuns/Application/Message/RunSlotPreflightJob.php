<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Story 9.42: drives one slot's solo test generation. Dispatched after the yaml save
 * commits; the handler starts the orchestrator job then re-dispatches itself (delayed)
 * until the job settles, and records the verdict on the participant slot. yamlSha pins the
 * verdict to the exact yaml tested: an edit while the check runs makes the result stale
 * and it is dropped.
 */
final readonly class RunSlotPreflightJob
{
    public function __construct(
        public string $runId,
        public string $userId,
        public string $slotId,
        public string $yamlSha,
        public ?string $orchestratorJobId = null,
        public int $polls = 0,
    ) {
    }
}
