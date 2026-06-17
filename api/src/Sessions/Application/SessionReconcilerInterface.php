<?php

declare(strict_types=1);

namespace App\Sessions\Application;

interface SessionReconcilerInterface
{
    /** @return array<string, mixed> */
    public function transition(string $sessionId, string $newStatus): array;

    /** @return array<string, mixed> */
    public function transitionToRunningFromOrchestrateur(string $sessionId, int $apPort, ?int $bridgePort): array;

    /**
     * Force-resolve a session stuck in a transitional ("pending") status by reconciling against the
     * orchestrateur's real state. See SessionLifecycleManager::reconcilePending for the decision table.
     *
     * @return array{found: bool, from?: string, action?: string, to?: string|null}
     */
    public function reconcilePending(string $sessionId): array;
}
