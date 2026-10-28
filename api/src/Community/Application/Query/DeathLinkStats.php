<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 32.25: a member's DeathLink deaths ({@see DeathLinkStatsQueryInterface}).
 */
final readonly class DeathLinkStats
{
    public function __construct(
        public int $deaths,
        public int $victims,
        public int $maxDeathsInSession,
    ) {
    }
}
