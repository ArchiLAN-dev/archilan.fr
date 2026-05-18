<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminWeeklyTemplateDetailQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminWeeklyTemplateDetailController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminWeeklyTemplateDetailQuery $detailQuery,
    ) {
    }

    #[Route('/api/v1/admin/weekly-templates/{id}', name: 'api_admin_weekly_templates_detail', methods: ['GET'])]
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $data = $this->detailQuery->execute($id);

        if (null === $data) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $data]);
    }
}
