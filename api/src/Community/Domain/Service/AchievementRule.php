<?php

declare(strict_types=1);

namespace App\Community\Domain\Service;

use App\Community\Domain\ValueObject\MetricBag;

/**
 * A node in an achievement's unlock rule tree (story 30.16): either a boolean group or a leaf condition.
 * Pure - evaluated against a MetricBag, serialisable to/from the stored JSON.
 */
interface AchievementRule
{
    public function matches(MetricBag $bag): bool;

    /**
     * Story 30.53: the same evaluation, node by node - each condition with the member's current value, each group
     * with whether it holds.
     *
     * @return array<string, mixed>
     */
    public function progress(MetricBag $bag): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
