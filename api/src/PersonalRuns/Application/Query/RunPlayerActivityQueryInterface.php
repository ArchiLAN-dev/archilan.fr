<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

interface RunPlayerActivityQueryInterface
{
    /** Every player of the session's slots, owners and co-players, in one read. */
    public function forSession(string $sessionId): RunPlayersActivity;

    /** A block either way between the two members. */
    public function blockedBetween(string $userId, string $otherId): bool;
}
