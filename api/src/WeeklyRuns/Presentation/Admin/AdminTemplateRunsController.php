<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminTemplateRunsQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminTemplateRunsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminTemplateRunsQuery $templateRunsQuery,
    ) {
    }

    #[Route('/api/v1/admin/weekly-templates/{templateId}/runs', name: 'api_admin_weekly_template_runs', methods: ['GET'])]
    public function __invoke(Request $request, string $templateId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $data = $this->templateRunsQuery->execute($templateId);

        return new JsonResponse(['data' => $data]);
    }
}
