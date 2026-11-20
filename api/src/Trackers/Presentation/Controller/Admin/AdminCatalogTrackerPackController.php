<?php

declare(strict_types=1);

namespace App\Trackers\Presentation\Controller\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Trackers\Application\Query\CatalogTrackerPackSuggestions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 54.12: the PopTracker packs the community catalog names, for a game and for the whole library. Admins
 * only; importing one goes through the usual import (54.1).
 */
final readonly class AdminCatalogTrackerPackController
{
    use RequiresAuthTrait;

    public function __construct(
        private CatalogTrackerPackSuggestions $suggestions,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/admin/games/{gameId}/tracker-pack-suggestions', name: 'api_admin_game_tracker_pack_suggestions', methods: ['GET'])]
    public function forGame(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $suggestions = $this->suggestions->forGame($gameId);
        if (null === $suggestions) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        return new JsonResponse(['data' => $suggestions, 'meta' => []]);
    }

    #[Route('/api/v1/admin/tracker-pack-suggestions', name: 'api_admin_tracker_pack_suggestions', methods: ['GET'])]
    public function gamesToEquip(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->suggestions->gamesToEquip(), 'meta' => []]);
    }
}
