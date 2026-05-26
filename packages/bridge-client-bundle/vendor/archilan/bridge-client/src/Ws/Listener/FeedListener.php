<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws\Listener;

use Archilan\BridgeClient\Ws\Message\BridgeEvent;

/**
 * Receives AP server messages: chat, print output, hints printed, goal broadcasts, etc.
 *
 * Relevant payload keys: `message` (string), `type` (string, e.g. "chat"/"print").
 */
interface FeedListener extends WsSubscriber
{
    public function onFeed(BridgeEvent $event): void;
}
