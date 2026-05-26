<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Notified when the reachability analysis completes or is invalidated for a slot.
 *
 * Relevant payload keys: `slot` (int), `cached` (bool).
 */
interface ReachableChangedListener extends WsSubscriber
{
    public function onReachableChanged(BridgeEvent $event): void;
}
