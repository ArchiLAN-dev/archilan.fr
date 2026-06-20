<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * A node in an achievement's unlock rule tree (story 30.16): either a boolean group or a leaf condition.
 * Pure - evaluated against a MetricBag, serialisable to/from the stored JSON.
 */
interface AchievementRule
{
    public function matches(MetricBag $bag): bool;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
