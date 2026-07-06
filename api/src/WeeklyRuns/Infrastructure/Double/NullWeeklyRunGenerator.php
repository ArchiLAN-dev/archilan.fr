<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure\Double;

use App\WeeklyRuns\Application\Port\WeeklyRunGeneratorInterface;

final class NullWeeklyRunGenerator implements WeeklyRunGeneratorInterface
{
    public function generate(
        string $weeklyRunId,
        string $apworldStorageKey,
        string $templateYaml,
        string $seed,
        array $generationOptions = [],
    ): void {
        // No-op: generation completion is simulated in tests via the webhook path.
    }
}
