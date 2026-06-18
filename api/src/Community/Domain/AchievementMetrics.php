<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The derived inputs an achievement is evaluated against, composed from existing Epic-18 read models.
 */
final readonly class AchievementMetrics
{
    public function __construct(
        public int $runsParticipated,
        public int $goalCompletions,
        public int $totalChecksDone,
        public int $totalItemsReceived,
        public int $distinctGames,
    ) {
    }

    public function valueFor(string $metric): int
    {
        return match ($metric) {
            AchievementDefinition::METRIC_RUNS => $this->runsParticipated,
            AchievementDefinition::METRIC_GOALS => $this->goalCompletions,
            AchievementDefinition::METRIC_CHECKS => $this->totalChecksDone,
            AchievementDefinition::METRIC_ITEMS => $this->totalItemsReceived,
            AchievementDefinition::METRIC_DISTINCT_GAMES => $this->distinctGames,
            default => 0,
        };
    }
}
