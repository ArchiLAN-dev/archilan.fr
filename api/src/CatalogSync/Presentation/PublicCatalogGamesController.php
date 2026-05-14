<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\CatalogSyncService;
use App\CatalogSync\Domain\CatalogEntry;
use App\GameSelection\Domain\GameCatalogSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublicCatalogGamesController
{
    public function __construct(
        private CatalogSyncService $catalogSyncService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Returns the names of Archipelago catalog games not yet imported on the platform.
     * Sorted alphabetically. Used to populate the game-request combobox.
     */
    #[Route('/api/v1/catalog-games', name: 'api_catalog_games_public', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $entries = $this->catalogSyncService->fetchSheet();
        } catch (\Throwable) {
            return new JsonResponse(['data' => []]);
        }

        // Collect all catalog_sheet_name values that are already imported.
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('cs.catalogSheetName')
            ->from(GameCatalogSync::class, 'cs')
            ->where('cs.catalogSheetName IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $importedNames = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['catalogSheetName'] ?? null)) {
                $importedNames[$row['catalogSheetName']] = true;
            }
        }

        $notImported = array_values(array_filter(
            $entries,
            static fn (CatalogEntry $e): bool => !isset($importedNames[$e->name]),
        ));

        usort($notImported, static fn (CatalogEntry $a, CatalogEntry $b): int => strcmp($a->name, $b->name));

        return new JsonResponse([
            'data' => array_map(static fn (CatalogEntry $e): string => $e->name, $notImported),
        ]);
    }
}
