<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Supplies one or more achievement facts for a user (story 30.16). Tagged so MetricBagBuilder can compose
 * every provider into a single MetricBag — adding a new combinable fact means adding a provider, with no
 * change to the rule engine or the admin form.
 */
#[AutoconfigureTag('community.achievement_metric_provider')]
interface AchievementMetricProviderInterface
{
    /**
     * @return array<string, int> fact key => value
     */
    public function metricsFor(string $userId): array;
}
