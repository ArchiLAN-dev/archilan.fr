<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameFollow;
use App\GameSelection\Domain\Repository\GameFollowRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * A member starts or stops following a game (story 38.22). Idempotent both ways.
 */
final readonly class FollowGame
{
    public function __construct(
        private GameRepositoryInterface $games,
        private GameFollowRepositoryInterface $follows,
        private ClockInterface $clock,
    ) {
    }

    public function set(string $userId, string $gameId, bool $follow): FollowGameOutcome
    {
        if (!$this->games->findById($gameId) instanceof Game) {
            return FollowGameOutcome::GameNotFound;
        }

        $existing = $this->follows->find($userId, $gameId);
        if ($follow && null === $existing) {
            $this->follows->save(GameFollow::create($userId, $gameId, $this->clock->now()));
        }
        if (!$follow && null !== $existing) {
            $this->follows->remove($existing);
        }

        return $follow ? FollowGameOutcome::Following : FollowGameOutcome::NotFollowing;
    }
}
