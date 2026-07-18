<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Command;

final readonly class WeeklyOptInResult
{
    public function __construct(
        public string $id,
        public string $weeklyRunId,
        public string $userId,
        public int $attemptNumber,
    ) {
    }
}
