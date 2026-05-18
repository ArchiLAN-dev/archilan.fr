<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\CurrentWeeklyRunsQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CurrentWeeklyRunsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CurrentWeeklyRunsQuery $currentWeeklyRunsQuery,
    ) {
    }

    #[Route('/api/v1/weekly-runs/current', name: 'api_weekly_runs_current', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->optionalUser($request);
        $data = $this->currentWeeklyRunsQuery->execute($user?->getId());

        return new JsonResponse(['data' => $data]);
    }
}
