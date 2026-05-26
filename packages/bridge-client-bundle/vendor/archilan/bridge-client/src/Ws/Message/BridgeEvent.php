<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Message;

use Archilan\BridgeClient\Ws\BridgeEventType;

/**
 * A real-time event broadcast by the bridge (join, chat, item_send, hint, goal, …).
 *
 * The `type` field matches the bridge's `event_type` string.
 * All remaining fields from the server frame are available in `payload`.
 */
final readonly class BridgeEvent implements BridgeMessage
{
    /**
     * @param array<string, mixed> $payload  All fields from the server frame except `type`.
     */
    public function __construct(
        public string $type,
        public array $payload,
    ) {
    }

    /**
     * Returns the typed enum case for known event types, or null for unknown ones.
     */
    public function eventType(): ?BridgeEventType
    {
        return BridgeEventType::tryFrom($this->type);
    }

    /**
     * Convenience: true when this event matches the given type.
     */
    public function is(BridgeEventType $type): bool
    {
        return $this->type === $type->value;
    }
}
