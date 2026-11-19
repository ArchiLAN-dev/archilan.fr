<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

/**
 * Story 17.37: the Archipelago save the orchestrateur copies to durable storage each time a game stops (it used
 * to live only on the session's Docker volume, which now goes away once unused). Implemented in Infrastructure.
 */
interface SessionSaveStorageInterface
{
    /** Storage key of the session's save, or null when none was stored (a game never played). */
    public function locate(string $sessionId): ?string;

    /** The save's contents, or null when none was stored or it cannot be read. */
    public function read(string $sessionId): ?string;
}
