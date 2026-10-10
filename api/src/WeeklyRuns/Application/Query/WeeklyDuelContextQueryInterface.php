<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

/**
 * What a weekly duel (story 43.15) reads around it: the weekly runs it is on, and the blocks between its members,
 * read across tables so WeeklyRuns does not import the Community domain.
 */
interface WeeklyDuelContextQueryInterface
{
    /**
     * @param list<string> $weeklyRunIds
     *
     * @return array<string, array{gameName: string|null, active: bool, startedAt: \DateTimeImmutable, weekYear: int, weekNumber: int}> keyed by weekly run id
     */
    public function runs(array $weeklyRunIds): array;

    /**
     * The blocks between the given members, as [blockerId, blockedId] pairs.
     *
     * @param list<string> $userIds
     *
     * @return list<array{0: string, 1: string}>
     */
    public function blocksAmong(array $userIds): array;

    /**
     * Story 43.19: the duels a member created on the weekly runs of a given week, whatever the template.
     */
    public function duelsCreatedInWeek(string $creatorId, int $weekYear, int $weekNumber): int;

    /**
     * Story 43.18: the weekly runs finished before the given instant that still have a duel to settle.
     *
     * @return list<string>
     */
    public function finishedRunsWithOpenDuels(\DateTimeImmutable $finishedBefore): array;
}
