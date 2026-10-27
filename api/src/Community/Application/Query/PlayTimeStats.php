<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 32.26: a member's connected play time ({@see PlayTimeStatsQueryInterface}), in seconds.
 */
final readonly class PlayTimeStats
{
    public function __construct(
        public int $totalSeconds,
        public int $longestSpanSeconds,
        public int $maxSessionSeconds,
    ) {
    }
}
