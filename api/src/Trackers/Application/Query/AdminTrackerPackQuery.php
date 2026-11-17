<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** The packs of a game, for the admin game editor (story 54.1). */
final readonly class AdminTrackerPackQuery
{
    public function __construct(private AdminTrackerPackQueryInterface $query)
    {
    }

    /** @return list<AdminTrackerPackView> */
    public function forGame(string $gameId): array
    {
        return $this->query->forGame($gameId);
    }
}
