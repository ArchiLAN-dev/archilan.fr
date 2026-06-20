<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\MetricBag;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Assembles a user's MetricBag from every registered metric provider (story 30.16).
 */
final readonly class MetricBagBuilder
{
    /**
     * @param iterable<AchievementMetricProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('community.achievement_metric_provider')]
        private iterable $providers,
    ) {
    }

    public function build(string $userId): MetricBag
    {
        $facts = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->metricsFor($userId) as $key => $value) {
                $facts[$key] = $value;
            }
        }

        return new MetricBag($facts);
    }
}
