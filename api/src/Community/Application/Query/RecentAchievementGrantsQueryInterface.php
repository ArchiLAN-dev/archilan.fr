<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

interface RecentAchievementGrantsQueryInterface
{
    /**
     * The most recently unlocked achievements across the whole community, newest first (story 30.38).
     * Restricted to listable members (a public slug, not deleted) - the same population the directory
     * lists, which is the visibility rule the achievements surface uses (see CommunityOverviewQuery on
     * why per-profile audience does not apply to achievements).
     *
     * @return list<array{userId: string, achievementKey: string, unlockedAt: string}>
     */
    public function recent(int $limit): array;
}
