<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 52.7: one generated session of the event, scored. `playthrough` is `read`, `missing` (no spoiler or one
 * without playthrough: spoiler level below 2) or `skipped` (nobody declared windows, nothing to read). `score` is the
 * number of items at risk.
 */
final readonly class DependencyGeneration
{
    /**
     * @param 'read'|'missing'|'skipped' $playthrough
     * @param list<DependencyPair>       $pairs       the most items first
     */
    public function __construct(
        public string $sessionId,
        public string $status,
        public string $playthrough,
        public int $score,
        public array $pairs,
    ) {
    }
}
