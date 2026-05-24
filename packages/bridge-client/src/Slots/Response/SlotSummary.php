<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class SlotSummary
{
    public function __construct(
        public int $slot,
        public string $name,
        public string $game,
        public string $type,
        public string $status,
        public bool $connected,
        public int $checksDone,
        public int $checksTotal,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            slot:        is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            name:        is_string($data['name'] ?? null) ? $data['name'] : '',
            game:        is_string($data['game'] ?? null) ? $data['game'] : '',
            type:        is_string($data['type'] ?? null) ? $data['type'] : '',
            status:      is_string($data['status'] ?? null) ? $data['status'] : '',
            connected:   (bool) ($data['connected'] ?? false),
            checksDone:  is_int($data['checksDone'] ?? null) ? $data['checksDone'] : 0,
            checksTotal: is_int($data['checksTotal'] ?? null) ? $data['checksTotal'] : 0,
        );
    }
}
