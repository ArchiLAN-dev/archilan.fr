<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminWeeklyTemplateListQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminWeeklyTemplateListController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminWeeklyTemplateListQuery $listQuery,
    ) {
    }

    #[Route('/api/v1/admin/weekly-templates', name: 'api_admin_weekly_templates_list', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->listQuery->execute();

        return new JsonResponse($result);
    }
}
