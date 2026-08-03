<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * The item-exchange graph of a finished multiworld, in slot-name space.
 *
 * Produced by {@see FeedGraphBuilder} from the session's live feed - pure data,
 * no ids and no timestamps (those are attached later at build time by
 * reconciling with the session slots).
 */
final readonly class RecapGraph
{
    /**
     * @param list<RecapNode>   $nodes
     * @param list<RecapEdge>   $edges                  one aggregated edge per from->to pair
     * @param array<string,int> $localItemCounts        slotName => number of items the slot kept for itself
     * @param array<string,int> $localProgressionCounts slotName => how many of those were progression items
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $localItemCounts,
        public array $localProgressionCounts = [],
    ) {
    }
}
