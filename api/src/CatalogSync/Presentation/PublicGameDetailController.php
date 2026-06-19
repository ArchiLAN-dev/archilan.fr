<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\PublicGameDetailQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicGameDetailController
{
    public function __construct(private PublicGameDetailQuery $publicGameDetailQuery)
    {
    }

    #[Route('/api/v1/games/{slug}', name: 'api_game_public_detail', methods: ['GET'], requirements: ['slug' => '[A-Za-z0-9\-]+'])]
    public function detail(string $slug): JsonResponse
    {
        $game = $this->publicGameDetailQuery->bySlug($slug);

        if (null === $game) {
            return new JsonResponse(['error' => 'Game not found'], 404);
        }

        return new JsonResponse(['data' => $game]);
    }
}
