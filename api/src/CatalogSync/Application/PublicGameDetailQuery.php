<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Application\GameCatalogQueryInterface;
use Psr\Log\LoggerInterface;

/**
 * Public detail view for a single game: the persisted GameSelection catalog data merged
 * with the Google-Sheet metadata (notes, download links, adult/bundled flags) resolved
 * on demand from the cached catalog sheet - no snapshot, consistent with the catalog-sync
 * "resolve on demand" model. Sheet failures degrade gracefully to no metadata.
 */
final readonly class PublicGameDetailQuery
{
    public function __construct(
        private GameCatalogQueryInterface $gameCatalogQuery,
        private CatalogSyncService $catalogSyncService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bySlug(string $slug): ?array
    {
        $base = $this->gameCatalogQuery->bySlug($slug);
        if (null === $base) {
            return null;
        }

        $notes = null;
        $links = [];
        $bundledWithAp = false;
        $adultContent = false;

        try {
            $entry = $this->catalogSyncService->findEntryForNames(
                $base['catalogSheetName'],
                $base['archipelagoGameName'],
                $base['name'],
            );

            if (null !== $entry) {
                $notes = $entry->notes;
                $links = $entry->links;
                $bundledWithAp = $entry->bundledWithAp;
                $adultContent = $entry->adultContent;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('catalog_sync.detail_metadata_failed', [
                'slug' => $slug,
                'error' => $exception->getMessage(),
            ]);
        }

        unset($base['catalogSheetName'], $base['archipelagoGameName']);

        $base['bundledWithAp'] = $bundledWithAp;
        $base['adultContent'] = $adultContent;
        $base['catalog'] = ['notes' => $notes, 'links' => $links];

        return $base;
    }
}
