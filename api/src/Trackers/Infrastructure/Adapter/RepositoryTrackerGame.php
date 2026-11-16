<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Adapter;

use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Trackers\Application\Port\TrackerGameInterface;

/** Checks a pack's game in the context that owns the games (story 54.1). */
final readonly class RepositoryTrackerGame implements TrackerGameInterface
{
    public function __construct(private GameRepositoryInterface $games)
    {
    }

    public function exists(string $gameId): bool
    {
        return null !== $this->games->findById($gameId);
    }
}
