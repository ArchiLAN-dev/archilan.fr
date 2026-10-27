<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

/**
 * A member's runs, goals and checks split by game (story 30.55), counted by the very rules of
 * {@see PlayerStatsQueryInterface}: finished sessions, a personal run only when the player reached a goal in it,
 * a released slot without goal kept out of the checks, completed weekly attempts. Goals and checks add up to the
 * totals; a run with several of the player's games counts once in each of them.
 */
interface PlayerGameStatsQueryInterface
{
    /**
     * @return array<string, array{runs: int, goals: int, checks: int}> game id => counts
     */
    public function perGameForUser(string $userId): array;
}
