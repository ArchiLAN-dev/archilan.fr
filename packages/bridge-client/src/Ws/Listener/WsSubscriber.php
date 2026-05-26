<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

/**
 * Marker interface for all bridge WebSocket subscribers.
 *
 * Implement one or more of the typed sub-interfaces (FeedListener, HeartbeatListener, …)
 * and register the object with WsEventDispatcher::subscribe() — routing is handled automatically.
 *
 * A single class may implement any combination of listener interfaces.
 */
interface WsSubscriber
{
}
