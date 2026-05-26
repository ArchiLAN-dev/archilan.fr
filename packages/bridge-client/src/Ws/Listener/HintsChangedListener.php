<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Notified when a hint is added, updated (status change), or found.
 *
 * Relevant payload keys: `slot` (int), `hints` (array — hint list for the affected slot).
 */
interface HintsChangedListener extends WsSubscriber
{
    public function onHintsChanged(BridgeEvent $event): void;
}
