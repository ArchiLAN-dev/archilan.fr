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
        $dir = sys_get_temp_dir().'/weekly-runs/'.$weeklyRunId.'/output';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/seed.archipelago';
        file_put_contents($path, '');

        return $path;
    }
}
