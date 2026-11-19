<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

interface AdminTrackerPackQueryInterface
{
    /** @return list<AdminTrackerPackView> the game's packs, oldest first */
    public function forGame(string $gameId): array;

    /** @return array<string, list<string>> the source links of every pack, by game id (story 54.12) */
    public function sourceUrlsByGame(): array;
}
