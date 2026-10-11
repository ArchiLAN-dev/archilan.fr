<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Story 43.28: a player chose or changed their games in a run; the owner hears of it once the choice has settled.
 * Dispatched with a delay of SETTLE_MS: a later change carries a newer list, and this job, finding the player's list
 * moved on, stays quiet.
 */
final readonly class NotifyRunGamesChosenJob
{
    public const int SETTLE_MS = 10 * 60 * 1000;

    /**
     * @param list<string> $gameIds the player's games when they saved, sorted
     */
    public function __construct(
        public string $runId,
        public string $userId,
        public array $gameIds,
    ) {
    }
}
