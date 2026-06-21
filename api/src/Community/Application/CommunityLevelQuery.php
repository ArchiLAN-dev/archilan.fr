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
 * every surface that shows a level (profile, run participant detail, /communaute directory…) reports the
 * exact same number.
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
        return $this->levelForMany([$userId])[$userId] ?? $this->fromComponents(0, 0, 0, 0);
    }

    /**
     * Batch variant: level/XP + headline stats per user, from the same canonical inputs. Pass null for
     * every user with finished activity or achievements; with an explicit list, every requested id gets an
     * entry (zero-filled when the user has no activity).
     *
     * @param list<string>|null $userIds
     *
     * @return array<string, array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, runsParticipated: int, goalCompletions: int, totalChecksDone: int, achievementsUnlocked: int}>
     */
    public function levelForMany(?array $userIds): array
    {
        if (null !== $userIds && [] === $userIds) {
            return [];
        }

        $stats = $this->playerStats->computeForUsers($userIds);
        $counts = $this->achievementGrants->countByUsers($userIds);

        $ids = null !== $userIds
            ? $userIds
            : array_values(array_unique([...array_keys($stats), ...array_keys($counts)]));

        $out = [];
        foreach ($ids as $userId) {
            $s = $stats[$userId] ?? null;
            $out[$userId] = $this->fromComponents(
                null !== $s ? $s['goal_completions'] : 0,
                null !== $s ? $s['total_checks_done'] : 0,
                null !== $s ? $s['runs_participated'] : 0,
                $counts[$userId] ?? 0,
            );
        }

        return $out;
    }

    /**
     * @return array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int, runsParticipated: int, goalCompletions: int, totalChecksDone: int, achievementsUnlocked: int}
     */
    private function fromComponents(int $goalCompletions, int $totalChecksDone, int $runsParticipated, int $achievementsUnlocked): array
    {
        $xp = CommunityXp::compute($goalCompletions, $totalChecksDone, $runsParticipated, $achievementsUnlocked);
        $level = Level::fromXp($xp);

        return [
            'level' => $level->level,
            'xp' => $xp,
            'xpIntoLevel' => $level->xpIntoLevel,
            'xpForNextLevel' => $level->xpForNextLevel,
            'runsParticipated' => $runsParticipated,
            'goalCompletions' => $goalCompletions,
            'totalChecksDone' => $totalChecksDone,
            'achievementsUnlocked' => $achievementsUnlocked,
        ];
    }
}
