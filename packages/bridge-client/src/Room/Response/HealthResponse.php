<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Room\Response;

final readonly class HealthResponse
{
    public function __construct(
        public string $status,
        public bool $wsConnected,
        public string $sessionId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status:      is_string($data['status'] ?? null) ? $data['status'] : '',
            wsConnected: (bool) ($data['wsConnected'] ?? false),
            sessionId:   is_string($data['sessionId'] ?? null) ? $data['sessionId'] : '',
        );
    }
}
