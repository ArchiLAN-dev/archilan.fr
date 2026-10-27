<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 32.25: the DeathLink deaths of a member's slots, for the achievement facts.
 */
interface DeathLinkStatsQueryInterface
{
    /**
     * Deaths sent over DeathLink from a slot the member plays: how many, how many players they probably took with
     * them, and the most in a single session.
     */
    public function forUser(string $userId): DeathLinkStats;
}
