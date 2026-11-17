<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

interface AdminTrackerPackQueryInterface
{
    /** @return list<AdminTrackerPackView> the game's packs, oldest first */
    public function forGame(string $gameId): array;
}
