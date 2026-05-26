<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class SlotChecksResponse
{
    /**
     * @param CheckLocation[] $locations
     */
    public function __construct(
        public int $slot,
        public int $total,
        public int $checkedCount,
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
                $locations[] = CheckLocation::fromArray($loc);
            }
        }

        return new self(
            slot:         is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            total:        is_int($data['total'] ?? null) ? $data['total'] : 0,
            checkedCount: is_int($data['checkedCount'] ?? null) ? $data['checkedCount'] : 0,
            locations:    $locations,
        );
    }
}
