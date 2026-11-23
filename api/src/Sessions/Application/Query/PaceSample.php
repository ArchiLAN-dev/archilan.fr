<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 56.2: one slot played to its goal - its game, who played it, and the checks per hour it showed.
 */
final readonly class PaceSample
{
    /**
     * @param list<string> $userIds
     */
    public function __construct(
        public string $gameId,
        public array $userIds,
        public float $checksPerHour,
    ) {
    }
}
