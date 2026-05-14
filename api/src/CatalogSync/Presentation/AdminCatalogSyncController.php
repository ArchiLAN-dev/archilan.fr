<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\CheckApworldUpdatesService;
use App\CatalogSync\Application\IgdbCandidate;
use App\CatalogSync\Application\IgdbEnrichmentService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminCatalogSyncController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private IgdbEnrichmentService $igdbEnrichmentService,
        private CheckApworldUpdatesService $checkApworldUpdatesService,
    ) {
    }

    #[Route('/api/v1/admin/catalog-sync/igdb-preview', name: 'api_catalog_sync_igdb_preview', methods: ['GET'])]
    public function igdbPreview(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $name = trim($request->query->getString('name'));

        if ('' === $name) {
            return $this->apiAccessGuard->errorResponse('igdb_name_required', 'Le paramètre "name" est obligatoire.', 422);
        }

        $candidates = $this->igdbEnrichmentService->search($name);

        return new JsonResponse([
            'data' => array_map(
                static fn (IgdbCandidate $c): array => [
                    'igdbId' => $c->igdbId,
                    'name' => $c->name,
                    'summary' => $c->summary,
                    'coverUrl' => $c->coverUrl,
                ],
                $candidates,
            ),
            'meta' => [],
        ]);
    }

    #[Route('/api/v1/admin/catalog-sync/check-updates', name: 'api_catalog_sync_check_updates', methods: ['POST'])]
    public function checkUpdates(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

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
