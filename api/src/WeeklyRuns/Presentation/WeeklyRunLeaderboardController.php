<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\WeeklyRuns\Application\WeeklyRunLeaderboardQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WeeklyRunLeaderboardController
{
    public function __construct(private WeeklyRunLeaderboardQuery $leaderboardQuery)
    {
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/leaderboard', name: 'api_weekly_runs_leaderboard', methods: ['GET'])]
    public function __invoke(string $weeklyRunId): JsonResponse
    {
        $data = $this->leaderboardQuery->execute($weeklyRunId);

        return new JsonResponse(['data' => $data]);
    }
}
