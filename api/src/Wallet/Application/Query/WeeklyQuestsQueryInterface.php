<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\ValueObject\QuestWeek;

interface WeeklyQuestsQueryInterface
{
    /**
     * Who accomplished each quest of the week (story 41.6), keyed by quest (WeeklyQuest value):
     *
     * - reach_a_goal: a slot the member plays (owner or co-player) reached its goal, or one of their weekly
     *   attempts did;
     * - play_with_someone_new: the member made a check in a session where another member, with whom they had never
     *   both made a check in a same session before the week, made one too;
     * - play_a_weekly: the member launched a weekly attempt and made at least a check in it (or reached its goal).
     *
     * @return array<string, list<string>>
     */
    public function completers(QuestWeek $week): array;

    /**
     * The quests already paid to the member for the week (their ledger keys), by quest value.
     *
     * @return list<string>
     */
    public function rewardedQuests(string $userId, QuestWeek $week): array;
}
