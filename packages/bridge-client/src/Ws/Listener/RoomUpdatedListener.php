<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Notified when room-level settings change (hint cost, forfeit/release/collect modes, …).
 *
 * Relevant payload keys: `room` (array — full room info dict).
 */
interface RoomUpdatedListener extends WsSubscriber
{
    public function onRoomUpdated(BridgeEvent $event): void;
}
