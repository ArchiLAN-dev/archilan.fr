<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;

interface WeeklyQuestsQueryInterface
{
    /**
     * What each member did over the week, per metric (story 41.15), from what was actually played:
     *
     * - goals: goals reached by a slot the member plays (owner or co-player), in a session or a weekly attempt;
     * - checks: checks of the session feed made by a slot the member plays;
     * - weeklies: weekly attempts whose goal is reached in the week (story 41.33);
     * - newPartners: other members who made a check in a same session as the member this week, and with whom the
     *   member had never both made a check in a same session before the week (story 41.6);
     * - sessions: sessions where the member made a check;
     * - distinctGames: games the member made a check in.
     *
     * A member with nothing for a metric is absent from it. Story 41.18: an objective aimed at a game or an event
     * counts only the sessions of that game or event (weekly attempts left out), under its own key.
     *
     * @param list<QuestObjective> $objectives
     * @param string|null          $userId     one member only, or everyone
     *
     * @return array<string, array<string, int>> objective key => member id => count
     */
    public function counts(QuestWeek $week, array $objectives, ?string $userId = null): array;

    /**
     * Story 41.18: what an objective may aim at - the games played on the site, the events out of draft.
     *
     * @return array{games: list<array{id: string, name: string}>, events: list<array{id: string, title: string}>}
     */
    public function scopeOptions(): array;

    /**
     * The quests already paid to the member for the week (their ledger keys `quest:{week}:{quest}:{member}`).
     *
     * @return list<string> quest ids
     */
    public function rewardedQuests(string $userId, QuestWeek $week): array;

    /**
     * Story 41.17: the members who played between the two instants - a check in a session, or a weekly attempt
     * launched with a check or its goal.
     *
     * @return list<string>
     */
    public function activeMembers(\DateTimeImmutable $since, \DateTimeImmutable $until): array;

    /**
     * Story 41.17: what the member earned with the quests, by week (quests paid, pelles, chest opened).
     *
     * @return array<string, array{quests: list<string>, pelles: int, chest: bool}> by week key
     */
    public function earnedBy(string $userId): array;

    /** Story 41.16: whether the member's chest of the week is paid (key `quest-chest:{week}:{member}`). */
    public function chestPaid(string $userId, QuestWeek $week): bool;

    /**
     * Story 41.16: what the quests and chests paid, by week, read from the ledger keys.
     *
     * @return array<string, array{quests: array<string, array{members: int, pelles: int}>, chests: int, chestPelles: int}> by week key
     */
    public function payments(): array;
}
