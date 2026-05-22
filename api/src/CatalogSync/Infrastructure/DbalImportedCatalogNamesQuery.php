<?php

declare(strict_types=1);

namespace App\CatalogSync\Infrastructure;

use App\CatalogSync\Application\ImportedCatalogNamesQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalImportedCatalogNamesQuery implements ImportedCatalogNamesQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function list(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('cs.catalog_sheet_name')
            ->from('game_catalog_sync', 'cs')
            ->where($qb->expr()->isNotNull('cs.catalog_sheet_name'))
            ->executeQuery()
            ->fetchAllAssociative();

        $names = [];
        foreach ($rows as $row) {
            if (is_string($row['catalog_sheet_name'] ?? null)) {
                $names[] = $row['catalog_sheet_name'];
            }
        }

        return $names;
    }
}
