<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

interface PublicTrackerPackQueryInterface
{
    /** @return list<PublicTrackerPackView> the enabled packs of the game, its default first, then the oldest */
    public function enabledForGame(string $gameId): array;
}
