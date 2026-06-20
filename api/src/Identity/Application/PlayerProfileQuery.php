<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;

final readonly class PlayerProfileQuery
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PlayerStatsQueryInterface $statsQuery,
    ) {
    }

    /**
     * @return array{
     *     slug: string|null,
     *     displayName: string|null,
     *     joinedAt: string,
     *     stats: array{
     *         runsParticipated: int,
     *         goalCompletions: int,
     *         goalCompletionRate: float,
     *         totalChecksDone: int,
     *         totalItemsReceived: int
     *     }
     * }|null
     */
    public function execute(string $slug): ?array
    {
        $user = $this->userRepository->findBySlug($slug);
        if (!$user instanceof User) {
            return null;
        }

        $stats = $this->statsQuery->computeForUser($user->getId());
        $runsParticipated = $stats['runs_participated'];
        $gamesPlayed = $stats['games_played'];
        $goalCompletions = $stats['goal_completions'];

        return [
            'slug' => $user->getSlug(),
            'displayName' => $user->getDisplayName(),
            'joinedAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'stats' => [
                'runsParticipated' => $runsParticipated,
                'goalCompletions' => $goalCompletions,
                // Share of the player's games whose goal they reached (story 18.8), bounded to 100%.
                'goalCompletionRate' => $gamesPlayed > 0
                    ? round(min(1.0, $goalCompletions / $gamesPlayed), 6)
                    : 0.0,
                'totalChecksDone' => $stats['total_checks_done'],
                'totalItemsReceived' => $stats['total_items_received'],
            ],
        ];
    }
}
