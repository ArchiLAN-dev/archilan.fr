<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Receives AP server lifecycle transitions: pause, resume, or failed restart.
 *
 * Payload keys:
 *   - `event`      (string)  — "paused" | "restarted" | "restart_failed"
 *   - `failedSave` (bool)    — present only on "restart_failed": true if the save was lost
 */
interface LifecycleListener extends WsSubscriber
{
    public function onLifecycle(BridgeEvent $event): void;
}
