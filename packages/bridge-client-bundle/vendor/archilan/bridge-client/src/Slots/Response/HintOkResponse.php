<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class HintOkResponse
{
    public function __construct(
        public int $slot,
        public int $locationId,
        public bool $free,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            slot:       is_int($data['slot'] ?? null) ? $data['slot'] : 0,
            locationId: is_int($data['locationId'] ?? null) ? $data['locationId'] : 0,
            free:       (bool) ($data['free'] ?? false),
        );
    }
}
