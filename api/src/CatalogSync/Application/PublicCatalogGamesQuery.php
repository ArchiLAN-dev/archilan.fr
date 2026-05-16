<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\CatalogSync\Domain\CatalogEntry;
use App\GameSelection\Domain\GameCatalogSync;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicCatalogGamesQuery
{
    private string $syncTable;

    public function __construct(
        private Connection $connection,
        private CatalogSyncService $catalogSyncService,
        EntityManagerInterface $em,
    ) {
        $this->syncTable = $em->getClassMetadata(GameCatalogSync::class)->getTableName();
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

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('cs.catalog_sheet_name')
            ->from($this->syncTable, 'cs')
            ->where($qb->expr()->isNotNull('cs.catalog_sheet_name'))
            ->executeQuery()
            ->fetchAllAssociative();

        $importedNames = [];
        foreach ($rows as $row) {
            if (is_string($row['catalog_sheet_name'] ?? null)) {
                $importedNames[$row['catalog_sheet_name']] = true;
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
