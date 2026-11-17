<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** The packs players are offered for a game (story 54.2). Public: the packs are public on GitHub too. */
final readonly class PublicTrackerPackQuery
{
    public function __construct(private PublicTrackerPackQueryInterface $query)
    {
    }

    /** @return list<PublicTrackerPackView> */
    public function enabledForGame(string $gameId): array
    {
        return $this->query->enabledForGame($gameId);
    }
}
