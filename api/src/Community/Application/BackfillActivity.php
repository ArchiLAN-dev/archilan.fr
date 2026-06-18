<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ActivityEntry;
use App\Identity\Application\PlayerHistoryQueryInterface;

/**
 * Reconstructs the activity feed from existing data (the deterministic source of truth, epic §E.1).
 * Currently materialises `run_finished` entries from every user's finished-run history; idempotent, so
 * it can run on a schedule. Live write-site dispatch (Sessions/Registrations) is a best-effort add-on.
 */
final readonly class BackfillActivity
{
    public function __construct(
        private CommunityUserIdsQueryInterface $userIds,
        private PlayerHistoryQueryInterface $history,
        private RecordActivity $recordActivity,
    ) {
    }

    /**
     * @return int the number of newly recorded entries
     */
    public function run(): int
    {
        $recorded = 0;
        foreach ($this->userIds->allUserIds() as $userId) {
            foreach ($this->history->fetchForUser($userId) as $row) {
                $finishedAt = $row['finished_at'] ?? null;
                $game = $row['game'] ?? null;
                $sessionId = $row['session_id'] ?? null;
                if (!is_string($finishedAt) || '' === $finishedAt || !is_string($game) || !is_string($sessionId)) {
                    continue;
                }

                try {
                    $occurredAt = new \DateTimeImmutable($finishedAt);
                } catch (\Exception) {
                    continue;
                }

                $event = is_string($row['event_name'] ?? null) ? $row['event_name'] : null;
                $added = $this->recordActivity->record(
                    $userId,
                    ActivityEntry::TYPE_RUN_FINISHED,
                    $sessionId.':'.$game,
                    $occurredAt,
                    ['game' => $game, 'event' => $event, 'sessionId' => $sessionId],
                );
                if ($added) {
                    ++$recorded;
                }
            }
        }

        return $recorded;
    }
}
