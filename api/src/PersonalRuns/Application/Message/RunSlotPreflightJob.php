<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Story 9.42: drives one slot's solo test generation. Dispatched after the yaml save
 * commits; the handler starts the orchestrator job then re-dispatches itself (delayed)
 * until the job settles, and records the verdict on the participant slot. yamlSha pins the
 * verdict to the exact yaml tested: an edit while the check runs makes the result stale
 * and it is dropped. apworldHash pins it to the apworld tested (story 38.4 review): a slot moved to
 * another apworld meanwhile (story 38.7) keeps the same yaml, so the sha alone would not see it. Null
 * on a job queued before that: the apworld is then not checked.
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
        public ?string $apworldHash = null,
    ) {
    }
}
