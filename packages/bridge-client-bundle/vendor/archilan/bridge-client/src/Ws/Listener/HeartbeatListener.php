<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Notified on the periodic keep-alive pulse emitted by the bridge every 30 seconds.
 *
 * Relevant payload keys: `sessionId` (string), `wsConnected` (bool).
 */
interface HeartbeatListener extends WsSubscriber
{
    public function onHeartbeat(BridgeEvent $event): void;
}
