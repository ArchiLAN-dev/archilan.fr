<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Message;

use Archilan\BridgeClient\Room\Response\RoomInfo;
use Archilan\BridgeClient\Slots\Response\SlotSummary;

/**
 * Full state snapshot sent by the bridge immediately after a WebSocket connection is accepted.
 *
 * `room` and `slots` may be null when the bridge is not yet connected to Archipelago.
 */
final readonly class SnapshotMessage implements BridgeMessage
{
    /**
     * @param SlotSummary[] $slots
     */
    public function __construct(
        public string $sessionId,
        public ?RoomInfo $room,
        public array $slots,
        public bool $wsConnected,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $room = null;
        if (is_array($data['room'] ?? null)) {
            /** @var array<string, mixed> $roomData */
            $roomData = $data['room'];
            $room = RoomInfo::fromArray($roomData);
        }

        $slots = [];
        if (is_array($data['slots'] ?? null)) {
            foreach ($data['slots'] as $slotData) {
                if (is_array($slotData)) {
                    /** @var array<string, mixed> $slotData */
                    $slots[] = SlotSummary::fromArray($slotData);
                }
            }
        }

        return new self(
            sessionId:   is_string($data['sessionId'] ?? null) ? $data['sessionId'] : '',
            room:        $room,
            slots:       $slots,
            wsConnected: (bool) ($data['wsConnected'] ?? false),
        );
    }
}
