<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

use Archilan\BridgeClient\Enum\HintStatus;

final readonly class Hint
{
    public bool $found;

    public function __construct(
        public int $receivingSlot,
        public string $receivingPlayerName,
        public int $findingSlot,
        public string $findingPlayerName,
        public int $locationId,
        public string $locationName,
        public int $itemId,
        public string $itemName,
        public int $itemFlags,
        public string $entrance,
        public HintStatus $status,
    ) {
        $this->found = $status->isFound();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $statusInt = is_int($data['status'] ?? null) ? $data['status'] : 0;
        $status = HintStatus::tryFrom($statusInt) ?? HintStatus::Unspecified;

        return new self(
            receivingSlot:       is_int($data['receivingPlayer'] ?? null) ? $data['receivingPlayer'] : 0,
            receivingPlayerName: is_string($data['receivingPlayerName'] ?? null) ? $data['receivingPlayerName'] : '',
            findingSlot:         is_int($data['findingPlayer'] ?? null) ? $data['findingPlayer'] : 0,
            findingPlayerName:   is_string($data['findingPlayerName'] ?? null) ? $data['findingPlayerName'] : '',
            locationId:          is_int($data['locationId'] ?? null) ? $data['locationId'] : 0,
            locationName:        is_string($data['locationName'] ?? null) ? $data['locationName'] : '',
            itemId:              is_int($data['itemId'] ?? null) ? $data['itemId'] : 0,
            itemName:            is_string($data['itemName'] ?? null) ? $data['itemName'] : '',
            itemFlags:           is_int($data['itemFlags'] ?? null) ? $data['itemFlags'] : 0,
            entrance:            is_string($data['entrance'] ?? null) ? $data['entrance'] : '',
            status:              $status,
        );
    }
}
