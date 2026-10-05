<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestWeek;

interface WeeklyQuestsQueryInterface
{
    /**
     * What each member did over the week, per metric (story 41.15), from what was actually played:
     *
     * - goals: goals reached by a slot the member plays (owner or co-player), in a session or a weekly attempt;
     * - checks: checks of the session feed made by a slot the member plays;
     * - weeklies: weekly attempts launched with at least a check or the goal;
     * - newPartners: other members who made a check in a same session as the member this week, and with whom the
     *   member had never both made a check in a same session before the week (story 41.6);
     * - sessions: sessions where the member made a check;
     * - distinctGames: games the member made a check in.
     *
     * A member with nothing for a metric is absent from it.
     *
     * @param list<QuestMetric> $metrics
     * @param string|null       $userId  one member only, or everyone
     *
     * @return array<string, array<string, int>> metric => member id => count
     */
    public function counts(QuestWeek $week, array $metrics, ?string $userId = null): array;

    /**
     * The quests already paid to the member for the week (their ledger keys `quest:{week}:{quest}:{member}`).
     *
     * @return list<string> quest ids
     */
    public function rewardedQuests(string $userId, QuestWeek $week): array;
}
