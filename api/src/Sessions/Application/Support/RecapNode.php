<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * A node in the item-exchange graph: one Archipelago slot, keyed by its slot
 * name (the name the AP server broadcasts), with the game it played.
 */
final readonly class RecapNode
{
    public function __construct(
        public string $slotName,
        public string $game,
    ) {
    }
}
