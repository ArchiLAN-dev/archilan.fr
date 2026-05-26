<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class ItemLocation
{
    public function __construct(
        public int $itemId,
        public string $itemName,
        public int $locationId,
        public string $locationName,
        public int $findingSlot,
        public ?string $findingPlayerName,
        public string $checkStatus,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            itemId:            is_int($data['itemId'] ?? null) ? $data['itemId'] : 0,
            itemName:          is_string($data['itemName'] ?? null) ? $data['itemName'] : '',
            locationId:        is_int($data['locationId'] ?? null) ? $data['locationId'] : 0,
            locationName:      is_string($data['locationName'] ?? null) ? $data['locationName'] : '',
            findingSlot:       is_int($data['findingPlayer'] ?? null) ? $data['findingPlayer'] : 0,
            findingPlayerName: is_string($data['findingPlayerName'] ?? null) ? $data['findingPlayerName'] : null,
            checkStatus:       is_string($data['checkStatus'] ?? null) ? $data['checkStatus'] : '',
        );
    }
}
