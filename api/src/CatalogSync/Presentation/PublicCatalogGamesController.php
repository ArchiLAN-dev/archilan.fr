<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\PublicCatalogGamesQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicCatalogGamesController
{
    public function __construct(private PublicCatalogGamesQuery $publicCatalogGamesQuery)
    {
    }

    /**
     * Returns the names of Archipelago catalog games not yet imported on the platform.
     * Sorted alphabetically. Used to populate the game-request combobox.
     */
    #[Route('/api/v1/catalog-games', name: 'api_catalog_games_public', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $names = $this->publicCatalogGamesQuery->list();

        if (null === $names) {
            return new JsonResponse(['data' => []]);
        }

        return new JsonResponse(['data' => $names]);
    }
}
