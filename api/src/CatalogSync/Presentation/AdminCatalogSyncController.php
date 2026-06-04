<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\CheckApworldUpdatesService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminCatalogSyncController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CheckApworldUpdatesService $checkApworldUpdatesService,
    ) {
    }

    #[Route('/api/v1/admin/catalog-sync/check-updates', name: 'api_catalog_sync_check_updates', methods: ['POST'])]
    public function checkUpdates(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->checkApworldUpdatesService->checkAll();

        return new JsonResponse([
            'data' => [
                'checked' => $result['checked'],
                'rateLimitHit' => $result['rateLimitHit'],
            ],
            'meta' => [],
        ]);
    }
}
