<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Notified when a slot's status, checks, received items, or connection state changes.
 *
 * Relevant payload keys: `slot` (int), `slots` (array — full summary of all slots).
 */
interface StateChangedListener extends WsSubscriber
{
    public function onStateChanged(BridgeEvent $event): void;
}
