<?php

declare(strict_types=1);

namespace Archilan\BridgeClientBundle\Ws;

use Archilan\BridgeClient\BridgeClient;
use Archilan\BridgeClient\Ws\Listener\WsSubscriber;
use Archilan\BridgeClient\Ws\WsEventDispatcher;
use Archilan\BridgeClientBundle\Bridge\BridgeClientFactory;

/**
 * Creates WsEventDispatcher instances pre-wired with all registered subscribers.
 *
 * The target bridge is specified at call time via a URL — one dispatcher per session.
 * All WsSubscriber services are injected automatically via `archi_bridge.ws_subscriber`.
 *
 * To listen to two sessions concurrently, create two dispatchers (each in its own process
 * or fiber), passing the respective bridge URL.
 *
 * Example (Symfony command or background worker):
 *
 *   // URL obtained at runtime from the orchestrateur session data
 *   $dispatcher = $factory->createForUrl("http://localhost:{$session->bridgePort}");
 *   echo $dispatcher->snapshot()->sessionId;
 *   $dispatcher->listen();
 *
 * Or pass an existing BridgeClient (e.g. from BridgeClientPool):
 *
 *   $client     = $pool->get($sessionId, $bridgeUrl);
 *   $dispatcher = $factory->createForClient($client);
 *   $dispatcher->listen();
 */
final class WsDispatcherFactory
{
    /** @param iterable<WsSubscriber> $subscribers Injected via tagged_iterator by the bundle. */
    public function __construct(
        private readonly BridgeClientFactory $bridgeClientFactory,
        private readonly iterable $subscribers,
    ) {
    }

    /**
     * Opens a WebSocket connection to the given bridge URL and returns a ready dispatcher.
     *
     * @throws \Archilan\BridgeClient\Ws\Exception\WsConnectionException on connection failure
     */
    public function createForUrl(string $baseUrl): WsEventDispatcher
    {
        return $this->createForClient($this->bridgeClientFactory->create($baseUrl));
    }

    /**
     * Opens a WebSocket connection using an existing BridgeClient (e.g. from BridgeClientPool).
     *
     * @throws \Archilan\BridgeClient\Ws\Exception\WsConnectionException on connection failure
     */
    public function createForClient(BridgeClient $client): WsEventDispatcher
    {
        $dispatcher = new WsEventDispatcher($client->ws()->connect());

        foreach ($this->subscribers as $subscriber) {
            $dispatcher->subscribe($subscriber);
        }

        return $dispatcher;
    }
}
