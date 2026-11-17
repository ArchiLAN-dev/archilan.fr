<?php

declare(strict_types=1);

namespace App\Trackers\Application\Port;

/** The games a pack can be attached to, read from the context that owns them (story 54.1). */
interface TrackerGameInterface
{
    public function exists(string $gameId): bool;
}
