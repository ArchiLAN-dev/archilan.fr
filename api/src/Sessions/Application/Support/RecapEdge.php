<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * An aggregated exchange edge: `from` sent `count` items to `to` across the run
 * (one edge per ordered slot-name pair, self-edges excluded - those are local
 * items). Slot names, not slot ids - the parser works purely off the spoiler.
 */
final readonly class RecapEdge
{
    public function __construct(
        public string $fromSlotName,
        public string $toSlotName,
        public int $count,
    ) {
    }
}
