<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * A code-defined achievement: a deterministic threshold over one derived metric. Definitions live in
 * code (the catalog), grants are persisted - so adding a definition later retroactively grants it on the
 * next recompute, no migration (epic §E.1).
 */
final readonly class AchievementDefinition
{
    public const METRIC_RUNS = 'runs';
    public const METRIC_GOALS = 'goals';
    public const METRIC_CHECKS = 'checks';
    public const METRIC_ITEMS = 'items';
    public const METRIC_DISTINCT_GAMES = 'distinctGames';

    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $metric,
        public int $threshold,
    ) {
    }

    public function isUnlockedBy(AchievementMetrics $metrics): bool
    {
        return $metrics->valueFor($this->metric) >= $this->threshold;
    }
}
