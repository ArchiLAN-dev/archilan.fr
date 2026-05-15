<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\CatalogSync\Domain\CatalogEntry;
use App\GameSelection\Domain\GameCatalogSync;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicCatalogGamesQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CatalogSyncService $catalogSyncService,
    ) {
    }

    /**
     * Returns null when the Google Sheets catalog is unreachable.
     *
     * @return list<string>|null
     */
    public function list(): ?array
    {
        try {
            $entries = $this->catalogSyncService->fetchSheet();
        } catch (\Throwable) {
            return null;
        }

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

        return array_map(static fn (CatalogEntry $e): string => $e->name, $notImported);
    }
}
