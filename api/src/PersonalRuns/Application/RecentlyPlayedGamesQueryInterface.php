<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

/**
 * Read model: the games a member has most recently played, derived on demand from their personal-run
 * history (no snapshot). "Played" = a game that appears in one of the user's launched runs
 * (Run::LAUNCHED_STATUSES). De-duplicated by game, newest play first.
 */
interface RecentlyPlayedGamesQueryInterface
{
    /**
     * @return list<array{gameId: string, lastPlayedAt: string, runTitle: string}> at most $limit entries,
     *                                                                             newest play first,
     *                                                                             one per distinct game
     */
    public function recentlyPlayed(string $userId, string $excludeRunId, int $limit = 3): array;
}
