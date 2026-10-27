<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Query\DeathLinkStatsQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;

/**
 * Story 32.25: the deaths a member sent over DeathLink, the players they took with them, and their worst session.
 */
final readonly class DeathLinkMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(private DeathLinkStatsQueryInterface $deaths)
    {
    }

    public function metricsFor(string $userId): array
    {
        $stats = $this->deaths->forUser($userId);

        return [
            AchievementMetricCatalog::FACT_DEATH_LINK_DEATHS => $stats->deaths,
            AchievementMetricCatalog::FACT_DEATH_LINK_VICTIMS => $stats->victims,
            AchievementMetricCatalog::FACT_MAX_DEATH_LINK_DEATHS_IN_SESSION => $stats->maxDeathsInSession,
        ];
    }
}
