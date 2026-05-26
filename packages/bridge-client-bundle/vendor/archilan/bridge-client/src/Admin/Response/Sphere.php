<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Admin\Response;

final readonly class Sphere
{
    /**
     * @param LocationPlacement[] $locations
     */
    public function __construct(
        public int $index,
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
                $locations[] = LocationPlacement::fromArray($loc);
            }
        }

        return new self(
            index:     is_int($data['index'] ?? null) ? $data['index'] : 0,
            locations: $locations,
        );
    }
}
