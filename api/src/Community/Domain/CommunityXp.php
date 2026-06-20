<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The canonical community XP formula (epic §30.5, review #8). Derived deterministically from the same
 * components as the Epic-18 community leaderboard (goals + checks are the leaderboard's two axes) plus
 * participation and achievements. This is the single ranking source the directory "top players" (30.15)
 * must reuse - do not introduce a competing score.
 */
final class CommunityXp
{
    public const XP_PER_GOAL = 500;
    public const XP_PER_CHECK = 1;
    public const XP_PER_RUN = 50;
    public const XP_PER_ACHIEVEMENT = 100;

    public static function compute(int $goalCompletions, int $totalChecksDone, int $runsParticipated, int $achievementsUnlocked): int
    {
        return max(0, $goalCompletions) * self::XP_PER_GOAL
            + max(0, $totalChecksDone) * self::XP_PER_CHECK
            + max(0, $runsParticipated) * self::XP_PER_RUN
            + max(0, $achievementsUnlocked) * self::XP_PER_ACHIEVEMENT;
    }
}
