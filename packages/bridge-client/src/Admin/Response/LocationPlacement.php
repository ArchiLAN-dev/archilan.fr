<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Admin\Response;

final readonly class LocationPlacement
{
    public function __construct(
        public int $locationId,
        public string $locationName,
        public int $itemId,
        public string $itemName,
        public int $receivingSlot,
        public string $receivingPlayerName,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            locationId:          is_int($data['locationId'] ?? null) ? $data['locationId'] : 0,
            locationName:        is_string($data['locationName'] ?? null) ? $data['locationName'] : '',
            itemId:              is_int($data['itemId'] ?? null) ? $data['itemId'] : 0,
            itemName:            is_string($data['itemName'] ?? null) ? $data['itemName'] : '',
            receivingSlot:       is_int($data['receivingSlot'] ?? null) ? $data['receivingSlot'] : 0,
            receivingPlayerName: is_string($data['receivingPlayerName'] ?? null) ? $data['receivingPlayerName'] : '',
        );
    }
}
