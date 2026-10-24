<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameFollowRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;

/**
 * Whether a member follows a game (story 38.22), and which ones they follow (story 38.23).
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

    /**
     * The games a member follows, by name. A follow whose game is gone is not listed.
     *
     * @return list<array{gameId: string, gameName: string, gameSlug: string}>
     */
    public function followedBy(string $userId): array
    {
        $ids = $this->follows->followedGameIds($userId);
        if ([] === $ids) {
            return [];
        }

        return array_map(static fn (Game $game): array => [
            'gameId' => $game->getId(),
            'gameName' => $game->getName(),
            'gameSlug' => $game->getSlug(),
        ], $this->games->findByIdsSortedByName($ids));
    }
}
