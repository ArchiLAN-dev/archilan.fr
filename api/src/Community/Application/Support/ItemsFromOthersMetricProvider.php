<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\AchievementMetricProviderInterface;
use App\Community\Application\Query\ItemsFromOthersQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;

/**
 * Story 30.49: the items received from the other players - not those of one's own world or slots, nor what a
 * release or a collect handed over.
 */
final readonly class ItemsFromOthersMetricProvider implements AchievementMetricProviderInterface
{
    public function __construct(private ItemsFromOthersQueryInterface $items)
    {
    }

    public function metricsFor(string $userId): array
    {
        return [AchievementMetricCatalog::FACT_ITEMS_FROM_OTHERS => $this->items->count($userId)];
    }
}
