<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Query\SocialPlayQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;

/**
 * Story 43.16: the facts of playing with others. `distinctFriendsPlayedWith` reads today's friends: ending a
 * friendship can lower it, but an achievement already granted stays (grants are monotonic).
 */
final readonly class SocialPlayMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(private SocialPlayQueryInterface $play)
    {
    }

    public function metricsFor(string $userId): array
    {
        $play = $this->play->forUser($userId);

        return [
            AchievementMetricCatalog::FACT_DISTINCT_COPLAYERS => $play['coplayers'],
            AchievementMetricCatalog::FACT_DISTINCT_FRIENDS_PLAYED_WITH => $play['friends'],
            AchievementMetricCatalog::FACT_MAX_FINISHED_WITH_SAME_PERSON => $play['maxFinishedWithSamePerson'],
            AchievementMetricCatalog::FACT_WEEKLY_DUELS_WON => $play['weeklyDuelsWon'],
        ];
    }
}
