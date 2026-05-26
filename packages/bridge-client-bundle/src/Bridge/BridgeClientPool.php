<?php

declare(strict_types=1);

namespace Archilan\BridgeClientBundle\Bridge;

use Archilan\BridgeClient\BridgeClient;

/**
 * Request-scoped cache of BridgeClient instances, keyed by session ID.
 *
 * Avoids creating a new BridgeClient on every call within the same request when
 * multiple bridge operations target the same session.
 *
 * The base URL is provided on first access. If the bridge port changes after a session
 * restart, call release() to evict the stale client before the next access.
 *
 * Typical usage in a controller or service:
 *
 *   $client = $this->pool->get($sessionId, "http://localhost:{$session->bridgePort}");
 *   $slots  = $client->slots()->list();
 */
final class BridgeClientPool
{
    /** @var array<string, BridgeClient> */
    private array $clients = [];

    public function __construct(private readonly BridgeClientFactory $factory)
    {
    }

    /**
     * Returns a cached BridgeClient for the given session, creating one if absent.
     *
     * @param string $sessionId Unique session identifier (used as cache key).
     * @param string $baseUrl   Bridge HTTP base URL — only used on first access.
     */
    public function get(string $sessionId, string $baseUrl): BridgeClient
    {
        return $this->clients[$sessionId] ??= $this->factory->create($baseUrl);
    }

    /**
     * Removes the cached client for the given session.
     *
     * Call this when a session is stopped or its bridge port changes.
     */
    public function release(string $sessionId): void
    {
        unset($this->clients[$sessionId]);
    }

    /**
     * Removes all cached clients — useful for test teardown or long-lived workers.
     */
    public function releaseAll(): void
    {
        $this->clients = [];
    }
}
