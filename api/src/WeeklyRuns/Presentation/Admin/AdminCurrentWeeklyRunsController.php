<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminCurrentWeeklyRunsQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminCurrentWeeklyRunsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminCurrentWeeklyRunsQuery $currentWeeklyRunsQuery,
    ) {
    }

    #[Route('/api/v1/admin/weekly-runs/current', name: 'api_admin_weekly_runs_current', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $data = $this->currentWeeklyRunsQuery->execute();

        return new JsonResponse(['data' => $data]);
    }
}
