<?php

declare(strict_types=1);

namespace App\Streaming\Application\Query;

use App\Sessions\Application\Port\PublicReachabilitySnapshotsInterface;
use App\Sessions\Application\Query\SessionQuery;

/**
 * Read side of the reachable overlay (story 50.3). An OBS source has no account, so it gets what the push left and
 * never reaches the bridge: only a participant's page may start a computation.
 */
final readonly class OverlayReachabilityQuery
{
    public function __construct(
        private SessionQuery $sessionQuery,
        private PublicReachabilitySnapshotsInterface $snapshots,
    ) {
    }

    /**
     * @return array{view: array<mixed>|null}|null null when the session is unknown
     */
    public function forSlot(string $sessionId, int $slotIndex): ?array
    {
        if (null === $this->sessionQuery->findById($sessionId)) {
            return null;
        }

        return ['view' => $this->snapshots->find($sessionId, $slotIndex)];
    }
}
