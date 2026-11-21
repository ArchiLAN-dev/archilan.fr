<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Hotfix v1.0.3: a member's count on one objective of a week, as the worker last counted it (AwardWeeklyQuests, every
 * five minutes). Mapped so the schema knows the table, but only ever written by
 * `QuestProgressSnapshotRepositoryInterface::record` (the week's objectives replaced at once) and read by the member's
 * quests, which no longer count the week's feed during a request.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'quest_progress_count')]
#[ORM\Index(name: 'idx_quest_progress_count_member', columns: ['week_key', 'user_id'])]
final class QuestProgressCount
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'week_key', type: 'string', length: 8)]
        private string $weekKey,
        #[ORM\Id]
        #[ORM\Column(name: 'objective_key', type: 'string', length: 120)]
        private string $objectiveKey,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'string', length: 36)]
        private string $userId,
        #[ORM\Column(type: 'integer')]
        private int $count,
    ) {
    }

    public function getWeekKey(): string
    {
        return $this->weekKey;
    }

    public function getObjectiveKey(): string
    {
        return $this->objectiveKey;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
