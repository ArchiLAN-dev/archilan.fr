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
        $goalCompletions = $stats['goal_completions'];

        return [
            'slug' => $user->getSlug(),
            'displayName' => $user->getDisplayName(),
            'joinedAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'stats' => [
                'runsParticipated' => $runsParticipated,
                'goalCompletions' => $goalCompletions,
                'goalCompletionRate' => $runsParticipated > 0
                    ? round($goalCompletions / $runsParticipated, 6)
                    : 0.0,
                'totalChecksDone' => $stats['total_checks_done'],
                'totalItemsReceived' => $stats['total_items_received'],
            ],
        ];
    }
}
