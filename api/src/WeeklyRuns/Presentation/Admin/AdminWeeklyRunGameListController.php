<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminWeeklyRunGameListQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminWeeklyRunGameListController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminWeeklyRunGameListQuery $gameListQuery,
    ) {
    }

    #[Route('/api/v1/admin/weekly-runs/games', name: 'api_admin_weekly_runs_games', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $data = $this->gameListQuery->execute();

        return new JsonResponse(['data' => $data]);
    }
}
