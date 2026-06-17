<?php

declare(strict_types=1);

namespace App\Streaming\Application;

use App\Sessions\Application\SessionQuery;

/**
 * Read side of the public overlay subscribe flow. Overlays are read-only (subscribe to the feed +
 * players topics, no spoilers, no writes) and are meant to be shown on stream, so access is granted by
 * a permanent, tokenless URL keyed on the session id: any caller for a known session gets a short-lived
 * subscriber JWT. Returns null when the session is unknown. Never grants publish or hint topics.
 */
final readonly class OverlaySubscribeQuery
{
    public function __construct(
        private SessionQuery $sessionQuery,
    ) {
    }

    /**
     * @return list<string>|null the subscribe-only topics to grant, or null if the session is unknown
     */
    public function resolveTopics(string $sessionId): ?array
    {
        if (null === $this->sessionQuery->findById($sessionId)) {
            return null;
        }

        return [
            'runs/'.$sessionId.'/feed',
            'runs/'.$sessionId.'/players',
            // Per-slot reachability (URI template) for the "checks réalisables" overlay - same data as the
            // progression page's reachable list. `{slot}` matches any slot index.
            'runs/'.$sessionId.'/slots/{slot}/reachable',
            // Overlay-only channel for the operator's "Test" button (see OverlayTestController). Player
            // progression pages do NOT subscribe to it, so test events never reach them.
            'runs/'.$sessionId.'/overlay-test',
        ];
    }
}
