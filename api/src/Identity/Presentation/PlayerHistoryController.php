<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\PlayerHistoryQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PlayerHistoryController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PlayerHistoryQuery $playerHistoryQuery,
    ) {
    }

    #[Route('/api/v1/players/{slug}/history', methods: ['GET'])]
    public function history(Request $request, string $slug): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = max(1, min(100, (int) $request->query->get('limit', '10')));

        $result = $this->playerHistoryQuery->execute($slug, $page, $limit);
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse($result);
    }
}
