<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation\Controller;

use App\CatalogSync\Application\Command\QueueApworldUpdateCheck;
use App\CatalogSync\Application\Query\ApworldUpdateCheckStatusQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalogue page's « Vérifier les mises à jour » (story 38.18): the click queues a pass for the worker and
 * answers at once; the page then follows the pass with the GET.
 */
final readonly class AdminCatalogSyncController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private QueueApworldUpdateCheck $queueUpdateCheck,
        private ApworldUpdateCheckStatusQuery $updateCheckStatus,
    ) {
    }

    #[Route('/api/v1/admin/catalog-sync/check-updates', name: 'api_catalog_sync_check_updates', methods: ['POST'])]
    public function checkUpdates(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->queueUpdateCheck->queue();
        if (!$result->queued) {
            // The page reads the pass already under way with the GET below.
            return $this->apiAccessGuard->errorResponse('already_running', 'Une vérification est déjà en cours : son résultat s\'affichera ici.', 409);
        }

        return new JsonResponse(['data' => $result->run?->toArray(), 'meta' => []], 202);
    }

    #[Route('/api/v1/admin/catalog-sync/check-updates', name: 'api_catalog_sync_check_updates_status', methods: ['GET'])]
    public function checkUpdatesStatus(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->updateCheckStatus->latest()?->toArray(), 'meta' => []]);
    }
}
