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
 *
 * requiredFileIds (story 44.7): the files the player joined to « Tester ma config », held by the orchestrator. Only
 * the job that starts the test carries them; the polls that follow carry none.
 */
final readonly class RunSlotPreflightJob
{
    /**
     * @param list<string> $requiredFileIds
     */
    public function __construct(
        public string $runId,
        public string $userId,
        public string $slotId,
        public string $yamlSha,
        public ?string $orchestratorJobId = null,
        public int $polls = 0,
        public ?string $apworldHash = null,
        public array $requiredFileIds = [],
    ) {
    }

    /**
     * Review of epic 44: unserialize() never runs the constructor, so a message queued before requiredFileIds
     * existed would leave it uninitialised and crash the handler that reads it. Such a message carries no file.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        foreach (['runId', 'userId', 'slotId', 'yamlSha'] as $key) {
            $value = $data[$key] ?? null;
            $this->{$key} = is_string($value) ? $value : '';
        }
        $orchestratorJobId = $data['orchestratorJobId'] ?? null;
        $this->orchestratorJobId = is_string($orchestratorJobId) ? $orchestratorJobId : null;
        $polls = $data['polls'] ?? 0;
        $this->polls = is_int($polls) ? $polls : 0;
        $apworldHash = $data['apworldHash'] ?? null;
        $this->apworldHash = is_string($apworldHash) ? $apworldHash : null;
        $ids = $data['requiredFileIds'] ?? [];
        $this->requiredFileIds = is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }
}
