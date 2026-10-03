<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Shared\Application\Support\StatsPeriod;

interface SessionStatsQueryInterface
{
    /**
     * The Parties section of the admin statistics page (story 42.2). Only counts and game names.
     *
     * - runsCreated: personal runs by creation date.
     * - runsLaunched: distinct runs with a session started in the bucket (a relaunch is the same run); the total
     *   is distinct over the period.
     * - eventSessionsLaunched: started sessions that are not a run's.
     * - weeklyLaunched / weeklyCompleted: weekly attempts launched / that reached their goal.
     * - goalsReached: session slots whose goal fell in the bucket.
     * - topGames: the 10 games with the most distinct players who made a check over the period (owners and
     *   co-players), with their number of checks.
     *
     * @return array{
     *     runningSessions: int,
     *     activeRuns: int,
     *     runsCreated: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     runsLaunched: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     eventSessionsLaunched: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     weeklyLaunched: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     weeklyCompleted: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     goalsReached: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     topGames: list<array{gameId: string, name: string, players: int, checks: int}>
     * }
     */
    public function stats(StatsPeriod $period): array;
}
