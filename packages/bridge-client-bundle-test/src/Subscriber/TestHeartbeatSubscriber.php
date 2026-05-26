<?php

declare(strict_types=1);

namespace App\Subscriber;

use Archilan\BridgeClient\Ws\Listener\HeartbeatListener;
use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Counts heartbeat events. Used to verify that multiple subscribers on the same interface all fire.
 */
final class TestHeartbeatSubscriber implements HeartbeatListener
{
    public int $count = 0;

    public function onHeartbeat(BridgeEvent $event): void
    {
        ++$this->count;
    }
}
