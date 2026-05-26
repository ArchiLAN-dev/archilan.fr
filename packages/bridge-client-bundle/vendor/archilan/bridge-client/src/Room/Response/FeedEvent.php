<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Room\Response;

final readonly class FeedEvent
{
    public function __construct(
        public string $type,
        public string $message,
        public ?string $timestamp,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type:      is_string($data['type'] ?? null) ? $data['type'] : '',
            message:   is_string($data['message'] ?? null) ? $data['message'] : '',
            timestamp: is_string($data['timestamp'] ?? null) ? $data['timestamp'] : null,
        );
    }
}
