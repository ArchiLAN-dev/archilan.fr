<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Query\RecapSuperlativesQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;

/**
 * Recap-superlative achievement facts (story 32.4): a `superlatives` total plus a sparse
 * `superlative:{key}` count for every superlative the player won in a public session recap.
 * Combinable facts with no change to the rule engine or recompute flow.
 */
final readonly class RecapSuperlativesMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(private RecapSuperlativesQueryInterface $superlatives)
    {
    }

    public function metricsFor(string $userId): array
    {
        $counts = $this->superlatives->superlativeCountsFor($userId);

        $facts = [AchievementMetricCatalog::FACT_SUPERLATIVES => array_sum($counts)];
        foreach ($counts as $key => $count) {
            $facts[AchievementMetricCatalog::SUPERLATIVE_PREFIX.$key] = $count;
        }

        return $facts;
    }
}
