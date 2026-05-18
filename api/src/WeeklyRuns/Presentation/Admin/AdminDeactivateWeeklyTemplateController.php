<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminDeactivateWeeklyTemplate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminDeactivateWeeklyTemplateController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminDeactivateWeeklyTemplate $deactivateWeeklyTemplate,
    ) {
    }

    #[Route('/api/v1/admin/weekly-templates/{id}', name: 'api_admin_weekly_templates_deactivate', methods: ['DELETE'])]
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $found = $this->deactivateWeeklyTemplate->execute($id);

        if (!$found) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
