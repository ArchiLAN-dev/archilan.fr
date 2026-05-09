<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\PublicGameCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicGameCatalogController
{
    public function __construct(
        private PublicGameCatalog $catalog,
    ) {
    }

    #[Route('/api/v1/games', name: 'api_game_selection_public_games', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $this->catalog->list($query, $page);

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalPages' => $result['totalPages'],
            ],
        ]);
    }
}
