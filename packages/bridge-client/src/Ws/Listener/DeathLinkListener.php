<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Notified when a death-link is triggered by any player.
 *
 * Relevant payload keys: `source` (string — player name), `cause` (string|null).
 */
interface DeathLinkListener extends WsSubscriber
{
    public function onDeathLink(BridgeEvent $event): void;
}
