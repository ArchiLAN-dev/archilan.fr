<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

final readonly class WeeklyRunLeaderboardQuery
{
    public function __construct(private WeeklyRunLeaderboardQueryInterface $query)
    {
    }

    /**
     * @return array{
     *   fastest: list<array<string, mixed>>,
     *   fewestChecks: list<array<string, mixed>>,
     *   fewestItems: list<array<string, mixed>>,
     *   participants: list<array<string, mixed>>,
     * }
     */
    public function execute(string $weeklyRunId): array
    {
        return $this->query->execute($weeklyRunId);
    }
}
