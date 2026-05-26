<?php

declare(strict_types=1);

namespace App\Subscriber;

use Archilan\BridgeClient\Ws\Listener\FeedListener;
use Archilan\BridgeClient\Ws\Listener\HeartbeatListener;
use Archilan\BridgeClient\Ws\Message\BridgeEvent;
use Archilan\BridgeClient\Ws\WsEventDispatcher;

/**
 * Collects feed and heartbeat events; stops the dispatcher after receiving 2 feed events.
 */
final class TestFeedSubscriber implements FeedListener, HeartbeatListener
{
    public int $feedCount      = 0;
    public int $heartbeatCount = 0;

    public ?WsEventDispatcher $dispatcher = null;

    public function onFeed(BridgeEvent $event): void
    {
        $msg = is_string($event->payload['message'] ?? null)
            ? $event->payload['message']
            : '(no message)';

        echo '    [feed] '.substr($msg, 0, 80)."\n";

        if (++$this->feedCount >= 2) {
            $this->dispatcher?->stop();
        }
    }

    public function onHeartbeat(BridgeEvent $event): void
    {
        ++$this->heartbeatCount;
        echo "    [heartbeat] alive\n";
    }
}
