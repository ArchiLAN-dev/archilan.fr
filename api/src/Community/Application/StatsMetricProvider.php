<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementMetricCatalog;
use App\Identity\Application\PlayerHistoryQueryInterface;
use App\Identity\Application\PlayerStatsQueryInterface;

/**
 * The baseline achievement facts derived from the Epic-18 read models (story 30.16): runs, goals, checks,
 * items and distinct games. This is the first AchievementMetricProvider; new facts get their own provider.
 */
final readonly class StatsMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(
        private PlayerStatsQueryInterface $stats,
        private PlayerHistoryQueryInterface $history,
    ) {
    }

    public function metricsFor(string $userId): array
    {
        $stats = $this->stats->computeForUser($userId);

        return [
            AchievementMetricCatalog::FACT_RUNS => $stats['runs_participated'],
            AchievementMetricCatalog::FACT_GOALS => $stats['goal_completions'],
            AchievementMetricCatalog::FACT_CHECKS => $stats['total_checks_done'],
            AchievementMetricCatalog::FACT_ITEMS => $stats['total_items_received'],
            AchievementMetricCatalog::FACT_DISTINCT_GAMES => $this->distinctGames($userId),
        ];
    }

    private function distinctGames(string $userId): int
    {
        $games = [];
        foreach ($this->history->fetchForUser($userId) as $row) {
            $game = $row['game'] ?? null;
            if (is_string($game) && '' !== $game) {
                $games[$game] = true;
            }
        }

        return count($games);
    }
}
