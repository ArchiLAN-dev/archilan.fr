<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Query\FeedCounterQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Repository\AchievementCounterRepositoryInterface;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;

/**
 * Story 30.57: `counter:{id}` for the counters an active achievement reads, and only those - none read, no query.
 */
final readonly class FeedCounterMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(
        private AchievementDefinitionRepositoryInterface $definitions,
        private AchievementCounterRepositoryInterface $counters,
        private FeedCounterQueryInterface $query,
    ) {
    }

    public function metricsFor(string $userId): array
    {
        $ids = [];
        foreach ($this->definitions->allActive() as $definition) {
            $ids = [...$ids, ...AchievementMetricCatalog::counterIdsIn($definition->getRule())];
        }
        if ([] === $ids) {
            return [];
        }

        $facts = [];
        foreach ($this->query->values($userId, $this->counters->findByIds(array_values(array_unique($ids)))) as $counterId => $value) {
            $facts[AchievementMetricCatalog::COUNTER_PREFIX.$counterId] = $value;
        }

        return $facts;
    }
}
