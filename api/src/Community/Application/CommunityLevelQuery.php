<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\CommunityXp;
use App\Community\Domain\Level;
use App\Identity\Application\PlayerStatsQueryInterface;

/**
 * Single source of truth for a user's community level/XP + headline stats. The canonical XP formula is
 * fed from the same aggregate player stats (Epic 18) and achievement grants the public profile uses, so
 * every surface that shows a level (profile, run participant detail…) reports the exact same number.
 */
final readonly class CommunityLevelQuery
{
    public function __construct(
        private PlayerStatsQueryInterface $playerStats,
        private AchievementGrantRepositoryInterface $achievementGrants,
    ) {
    }

    /**
     * @return array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, runsParticipated: int, goalCompletions: int, totalChecksDone: int, achievementsUnlocked: int}
     */
    public function levelFor(string $userId): array
    {
        $stats = $this->playerStats->computeForUser($userId);
        $achievementsUnlocked = count($this->achievementGrants->findByUser($userId));

        $xp = CommunityXp::compute(
            $stats['goal_completions'],
            $stats['total_checks_done'],
            $stats['runs_participated'],
            $achievementsUnlocked,
        );
        $level = Level::fromXp($xp);

        return [
            'level' => $level->level,
            'xp' => $xp,
            'xpIntoLevel' => $level->xpIntoLevel,
            'xpForNextLevel' => $level->xpForNextLevel,
            'runsParticipated' => $stats['runs_participated'],
            'goalCompletions' => $stats['goal_completions'],
            'totalChecksDone' => $stats['total_checks_done'],
            'achievementsUnlocked' => $achievementsUnlocked,
        ];
    }
}
