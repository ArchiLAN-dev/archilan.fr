<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Application\WeeklyRunGeneratorInterface;

final class NullWeeklyRunGenerator implements WeeklyRunGeneratorInterface
{
    public function generate(
        string $weeklyRunId,
        string $apworldStorageKey,
        string $templateYaml,
        string $seed,
    ): string {
        return '/dev/null/'.$weeklyRunId.'/output/seed.archipelago';
    }
}
