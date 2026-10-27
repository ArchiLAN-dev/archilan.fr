<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 32.26: the time a game client held the slots a member plays (owner or co-player).
 */
interface PlayTimeStatsQueryInterface
{
    public function forUser(string $userId): PlayTimeStats;
}
