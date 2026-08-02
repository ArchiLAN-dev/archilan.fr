<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * An aggregated exchange edge: `from` sent `count` items to `to` across the run
 * (one edge per ordered slot-name pair, self-edges excluded - those are local
 * items). Slot names, not slot ids - the builder works purely off the feed.
 */
final readonly class RecapEdge
{
    public function __construct(
        public string $fromSlotName,
        public string $toSlotName,
        public int $count,
        /** How many of those items were AP progression items (story 32.17) - always <= $count. */
        public int $progressionCount = 0,
    ) {
    }
}
