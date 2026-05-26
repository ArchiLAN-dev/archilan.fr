<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Ws;

use Archilan\BridgeClient\Ws\Listener\BridgeEventListener;
use Archilan\BridgeClient\Ws\Listener\DeathLinkListener;
use Archilan\BridgeClient\Ws\Listener\FeedListener;
use Archilan\BridgeClient\Ws\Listener\HeartbeatListener;
use Archilan\BridgeClient\Ws\Listener\HintsChangedListener;
use Archilan\BridgeClient\Ws\Listener\LifecycleListener;
use Archilan\BridgeClient\Ws\Listener\ReachableChangedListener;
use Archilan\BridgeClient\Ws\Listener\RestartApprovalHandler;
use Archilan\BridgeClient\Ws\Listener\RoomUpdatedListener;
use Archilan\BridgeClient\Ws\Listener\StateChangedListener;
use Archilan\BridgeClient\Ws\Listener\WsSubscriber;
use Archilan\BridgeClient\Ws\Message\ApproveRestartRequest;
use Archilan\BridgeClient\Ws\Message\BridgeEvent;
use Archilan\BridgeClient\Ws\Message\BridgeMessage;
use Archilan\BridgeClient\Ws\Message\SnapshotMessage;

/**
 * Subscriber-based event dispatcher for the bridge WebSocket.
 *
 * Register subscribers via subscribe(), then call listen() to start the blocking event loop.
 * Routing to typed handler methods is automatic — the dispatcher inspects which interfaces
 * each subscriber implements and calls only the relevant methods.
 *
 * Example:
 *
 *   $dispatcher = new WsEventDispatcher($client->ws()->connect());
 *   $dispatcher
 *       ->subscribe(new GameLogger())         // implements FeedListener
 *       ->subscribe(new ProgressTracker())    // implements StateChangedListener, HintsChangedListener
 *       ->subscribe(new RestartGuard())       // implements RestartApprovalHandler
 *       ->listen();
 *
 * Multiple subscribers implementing the same interface are all called, in registration order.
 * A subscriber may implement any combination of listener interfaces.
 */
final class WsEventDispatcher
{
    /** @var list<WsSubscriber> */
    private array $subscribers = [];

    public function __construct(private readonly WsConnection $connection)
    {
    }

    /**
     * Registers a subscriber. Safe to call before or during listen().
     *
     * @return static Fluent — allows chaining multiple subscribe() calls.
     */
    public function subscribe(WsSubscriber $subscriber): static
    {
        $this->subscribers[] = $subscriber;

        return $this;
    }

    /**
     * The initial state snapshot received when the connection was established.
     */
    public function snapshot(): SnapshotMessage
    {
        return $this->connection->snapshot();
    }

    /**
     * Starts the blocking event loop. Returns when the connection closes or stop() is called.
     */
    public function listen(): void
    {
        foreach ($this->connection->messages() as $message) {
            $this->dispatch($message);
        }
    }

    /**
     * Sends an AP server command over the open connection (e.g. "!help", "!hint Slot Item").
     *
     * Delegates directly to WsConnection::sendCommand(). Useful when a subscriber or the
     * caller needs to trigger AP server output without holding a direct connection reference.
     *
     * @throws \Archilan\BridgeClient\Ws\Exception\WsConnectionException on send failure
     */
    public function sendCommand(string $text, ?string $id = null): void
    {
        $this->connection->sendCommand($text, $id);
    }

    /**
     * Closes the underlying connection, causing the listen() loop to exit cleanly.
     *
     * Subscribers that need to terminate the loop early should hold a reference to this
     * dispatcher and call stop() from within their handler method.
     */
    public function stop(): void
    {
        $this->connection->close();
    }

    // ------------------------------------------------------------------
    // Internal routing
    // ------------------------------------------------------------------

    private function dispatch(BridgeMessage $message): void
    {
        if ($message instanceof ApproveRestartRequest) {
            $this->dispatchApproveRestart($message);

            return;
        }

        if ($message instanceof BridgeEvent) {
            $this->dispatchEvent($message);
        }
    }

    private function dispatchApproveRestart(ApproveRestartRequest $request): void
    {
        $approved = false;

        foreach ($this->subscribers as $sub) {
            if ($sub instanceof RestartApprovalHandler && $sub->onApproveRestart($request)) {
                $approved = true;
            }
        }

        $this->connection->respond($request->requestId, $approved);
    }

    private function dispatchEvent(BridgeEvent $event): void
    {
        $type = $event->eventType();

        foreach ($this->subscribers as $sub) {
            // Wildcard — always called regardless of event type
            if ($sub instanceof BridgeEventListener) {
                $sub->onBridgeEvent($event);
            }

            // Typed handlers — called only for the matching event type
            if (BridgeEventType::Feed === $type && $sub instanceof FeedListener) {
                $sub->onFeed($event);
            } elseif (BridgeEventType::StateChanged === $type && $sub instanceof StateChangedListener) {
                $sub->onStateChanged($event);
            } elseif (BridgeEventType::RoomUpdated === $type && $sub instanceof RoomUpdatedListener) {
                $sub->onRoomUpdated($event);
            } elseif (BridgeEventType::HintsChanged === $type && $sub instanceof HintsChangedListener) {
                $sub->onHintsChanged($event);
            } elseif (BridgeEventType::DeathLink === $type && $sub instanceof DeathLinkListener) {
                $sub->onDeathLink($event);
            } elseif (BridgeEventType::ReachableChanged === $type && $sub instanceof ReachableChangedListener) {
                $sub->onReachableChanged($event);
            } elseif (BridgeEventType::Heartbeat === $type && $sub instanceof HeartbeatListener) {
                $sub->onHeartbeat($event);
            } elseif (BridgeEventType::Lifecycle === $type && $sub instanceof LifecycleListener) {
                $sub->onLifecycle($event);
            }
        }
    }
}
