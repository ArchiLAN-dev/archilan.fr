<?php

declare(strict_types=1);

namespace App\Identity\Application\Query;

/**
 * What a member plays and has played, for the admin sheet (story 36.4).
 *
 * Personal runs are the piece that had no admin surface at all - the operational hole issue #387
 * describes when a run's owner is unavailable. The rest is assembly of reads that already existed.
 */
final readonly class AdminUserGaming
{
    /**
     * @param array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, runsParticipated: int, goalCompletions: int, totalChecksDone: int, achievementsUnlocked: int} $progress
     * @param array{discordId: string|null, discordUsername: string|null, steamProfile: string|null}                                                                          $accounts
     * @param list<array{id: string, title: string, status: string, sessionId: string|null}>                                                                                  $ownedRuns
     * @param list<array{id: string, title: string, status: string, sessionId: string|null}>                                                                                  $joinedRuns
     * @param list<array{sessionId: string|null, context: string|null, game: string|null, finishedAt: string|null}>                                                           $history
     */
    public function __construct(
        public array $progress,
        public array $accounts,
        public array $ownedRuns,
        public array $joinedRuns,
        public array $history,
    ) {
    }
}
