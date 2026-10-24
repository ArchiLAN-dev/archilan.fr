<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameFollowRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;

/**
 * Whether a member follows a game (story 38.22).
 */
final readonly class GameFollowQuery
{
    public function __construct(
        private GameRepositoryInterface $games,
        private GameFollowRepositoryInterface $follows,
    ) {
    }

    /** Null when the game does not exist. */
    public function isFollowing(string $userId, string $gameId): ?bool
    {
        if (!$this->games->findById($gameId) instanceof Game) {
            return null;
        }

        return null !== $this->follows->find($userId, $gameId);
    }
}
