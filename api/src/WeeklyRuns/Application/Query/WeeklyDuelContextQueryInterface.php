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
     * @return array<string, array{gameName: string|null, active: bool, startedAt: \DateTimeImmutable}> keyed by weekly run id
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
}
