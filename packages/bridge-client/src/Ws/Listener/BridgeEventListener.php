<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Wildcard listener — called for every BridgeEvent, including unknown future types.
 *
 * Use this when you need to handle all events in a single method (e.g. a generic logger).
 * Typed listeners (FeedListener, HeartbeatListener, …) are invoked in addition to this one
 * when both are implemented on the same subscriber.
 */
interface BridgeEventListener extends WsSubscriber
{
    public function onBridgeEvent(BridgeEvent $event): void;
}
