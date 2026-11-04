<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

/**
 * The last view of a slot's reachability that may be shown on stream (story 50.3): the player's view, without the
 * spheres. Fed by the bridge's pushes, so reading it computes nothing.
 */
interface PublicReachabilitySnapshotsInterface
{
    /** @param array<mixed> $view */
    public function record(string $sessionId, int $slotIndex, array $view): void;

    /** @return array<mixed>|null */
    public function find(string $sessionId, int $slotIndex): ?array;
}
