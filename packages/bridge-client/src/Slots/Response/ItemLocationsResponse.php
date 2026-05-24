<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class ItemLocationsResponse
{
    /**
     * @param ItemLocation[] $locations
     */
    public function __construct(
        public int $slot,
        public array $locations,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $locations = [];
        foreach (is_array($data['locations'] ?? null) ? $data['locations'] : [] as $loc) {
            if (is_array($loc)) {
                /** @var array<string, mixed> $loc */
                $locations[] = ItemLocation::fromArray($loc);
            }
        }

        return new self(
            slot:      is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            locations: $locations,
        );
    }
}
