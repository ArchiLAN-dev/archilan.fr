<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;

/**
 * The weekly quests' storage (story 41.15): the quests, what each week serves, whether its draw happened, and the
 * number of quests a week.
 */
interface QuestRepositoryInterface
{
    public const int DEFAULT_QUESTS_PER_WEEK = 3;
    public const int DEFAULT_CHEST_REWARD = 50;

    public function findQuest(string $id): ?QuestDefinition;

    /** @return list<QuestDefinition> every quest, newest first */
    public function allQuests(): array;

    public function saveQuest(QuestDefinition $quest): void;

    /**
     * @param list<string> $weekKeys
     *
     * @return array<string, list<QuestWeekEntry>> by week (every key present), each by position
     */
    public function entriesOfWeeks(array $weekKeys): array;

    public function saveEntry(QuestWeekEntry $entry): void;

    public function removeEntry(QuestWeekEntry $entry): void;

    /**
     * Claims the week's draw: true for the one caller that gets to draw it, false when it was already claimed.
     */
    public function claimDraw(string $weekKey, \DateTimeImmutable $now): bool;

    /**
     * @param list<string> $weekKeys
     *
     * @return list<string> those already drawn
     */
    public function drawnWeeks(array $weekKeys): array;

    /**
     * Story 41.16: the weeks each quest was served in (coming pinned weeks included).
     *
     * @return array<string, list<string>> quest id => week keys, oldest first
     */
    public function weeksServedByQuest(): array;

    public function questsPerWeek(): int;

    /** Story 41.16: the extra pelles for accomplishing every quest of a week (0: no chest). */
    public function chestReward(): int;

    public function changeChestReward(int $reward): void;

    /** Story 41.17: the last week announced to the members, null before the first announcement. */
    public function announcedWeek(): ?string;

    public function markAnnounced(string $weekKey): void;

    public function changeQuestsPerWeek(int $count): void;
}
