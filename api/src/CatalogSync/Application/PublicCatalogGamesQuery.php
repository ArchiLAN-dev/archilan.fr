<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\CatalogSync\Domain\CatalogEntry;

final readonly class PublicCatalogGamesQuery
{
    public function __construct(
        private ImportedCatalogNamesQueryInterface $importedNamesQuery,
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

        $importedNames = array_flip($this->importedNamesQuery->list());

        $notImported = array_values(array_filter(
            $entries,
            static fn (CatalogEntry $e): bool => !isset($importedNames[$e->name]),
        ));

        usort($notImported, static fn (CatalogEntry $a, CatalogEntry $b): int => strcmp($a->name, $b->name));

        return array_map(static fn (CatalogEntry $e): string => $e->name, $notImported);
    }
}
