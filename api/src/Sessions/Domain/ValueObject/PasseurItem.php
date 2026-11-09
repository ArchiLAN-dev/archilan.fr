<?php

declare(strict_types=1);

namespace App\Sessions\Domain\ValueObject;

/**
 * Story 52.3: an item a slot still waits for, in another world's unopened location, as the bridge reports it.
 * `sphere` is that location's sphere in the finding world, null when the world is not tracked.
 */
final readonly class PasseurItem
{
    public function __construct(
        public int $findingSlot,
        public string $findingPlayerName,
        public int $locationId,
        public string $locationName,
        public int $itemId,
        public string $itemName,
        public bool $progression,
        public ?int $sphere,
    ) {
    }

    /** One location of the multiworld: its world and its id. */
    public function key(): string
    {
        return $this->findingSlot.':'.$this->locationId;
    }
}
