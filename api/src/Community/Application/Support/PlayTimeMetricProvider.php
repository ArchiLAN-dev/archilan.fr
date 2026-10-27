<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Query\PlayTimeStatsQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;

/**
 * Story 32.26: a member's connected play time, in whole hours (total, one session) and minutes (longest stretch).
 */
final readonly class PlayTimeMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(private PlayTimeStatsQueryInterface $playTime)
    {
    }

    public function metricsFor(string $userId): array
    {
        $stats = $this->playTime->forUser($userId);

        return [
            AchievementMetricCatalog::FACT_PLAYED_HOURS => intdiv($stats->totalSeconds, 3600),
            AchievementMetricCatalog::FACT_LONGEST_PLAY_SPAN_MINUTES => intdiv($stats->longestSpanSeconds, 60),
            AchievementMetricCatalog::FACT_MAX_PLAYED_HOURS_IN_SESSION => intdiv($stats->maxSessionSeconds, 3600),
        ];
    }
}
