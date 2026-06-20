<?php

declare(strict_types=1);

namespace App\CatalogSync\Infrastructure;

use App\CatalogSync\Application\CatalogSyncService;
use App\GameSelection\Application\GameCatalogLinksProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves a game's catalog-sheet "Links & Downloads" by matching it against the synced sheet
 * (story 31.1). Lives in CatalogSync (which already depends on GameSelection) and implements the
 * GameSelection-owned interface, keeping the dependency direction correct. Sheet failures degrade
 * to no links so seeding never breaks.
 */
final readonly class CatalogSyncGameLinksProvider implements GameCatalogLinksProviderInterface
{
    public function __construct(
        private CatalogSyncService $catalogSyncService,
        private LoggerInterface $logger,
    ) {
    }

    public function linksFor(?string $catalogSheetName, ?string $archipelagoGameName, string $name): array
    {
        try {
            $entry = $this->catalogSyncService->findEntryForNames($catalogSheetName, $archipelagoGameName, $name);

            return null === $entry ? [] : $entry->links;
        } catch (\Throwable $exception) {
            $this->logger->warning('catalog_sync.links_provider_failed', [
                'name' => $name,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
