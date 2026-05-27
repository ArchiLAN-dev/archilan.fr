<?php

declare(strict_types=1);

namespace App\Sessions\Application;

interface SessionReconcilerInterface
{
    /** @return array<string, mixed> */
    public function transition(string $sessionId, string $newStatus): array;

    /** @return array<string, mixed> */
    public function transitionToRunningFromOrchestrateur(string $sessionId, int $apPort, ?int $bridgePort): array;
}
