<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Slots\Response;

final readonly class ReachableLocation
{
    public function __construct(
        public int $locationId,
        public string $locationName,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            locationId:   is_int($data['locationId'] ?? null) ? $data['locationId'] : 0,
            locationName: is_string($data['locationName'] ?? null) ? $data['locationName'] : '',
        );
    }
}
