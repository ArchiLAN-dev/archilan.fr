<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class CheckItem
{
    public function __construct(
        public int $id,
        public string $name,
        public int $flags,
        public int $receivingSlot,
        public string $receivingPlayerName,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id:                  is_int($data['id'] ?? null) ? $data['id'] : 0,
            name:                is_string($data['name'] ?? null) ? $data['name'] : '',
            flags:               is_int($data['flags'] ?? null) ? $data['flags'] : 0,
            receivingSlot:       is_int($data['receivingSlot'] ?? null) ? $data['receivingSlot'] : 0,
            receivingPlayerName: is_string($data['receivingPlayerName'] ?? null) ? $data['receivingPlayerName'] : '',
        );
    }
}
