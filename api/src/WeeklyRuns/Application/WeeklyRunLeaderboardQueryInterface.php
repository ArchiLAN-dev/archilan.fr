<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface WeeklyRunLeaderboardQueryInterface
{
    /**
     * @return array{
     *   fastest: list<array<string, mixed>>,
     *   fewestChecks: list<array<string, mixed>>,
     *   fewestItems: list<array<string, mixed>>,
     *   participants: list<array<string, mixed>>,
     * }
     */
    public function execute(string $weeklyRunId): array;
}
