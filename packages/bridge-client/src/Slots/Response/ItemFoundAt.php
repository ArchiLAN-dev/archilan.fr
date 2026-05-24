<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class ItemFoundAt
{
    public function __construct(
        public int $findingSlot,
        public string $findingPlayerName,
        public int $locationId,
        public string $locationName,
        public bool $checked,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            findingSlot:       is_int($data['findingSlot'] ?? null) ? $data['findingSlot'] : 0,
            findingPlayerName: is_string($data['findingPlayerName'] ?? null) ? $data['findingPlayerName'] : '',
            locationId:        is_int($data['locationId'] ?? null) ? $data['locationId'] : 0,
            locationName:      is_string($data['locationName'] ?? null) ? $data['locationName'] : '',
            checked:           (bool) ($data['checked'] ?? false),
        );
    }
}
