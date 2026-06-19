<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

/**
 * Supplies the curated "Links & Downloads" of a game from the synced catalog sheet, used to seed a
 * game's install tutorial (story 31.1). Defined in GameSelection and implemented by a CatalogSync
 * adapter so the dependency direction stays GameSelection ← CatalogSync (never the reverse).
 */
interface GameCatalogLinksProviderInterface
{
    /**
     * @return list<array{label: string, url: string|null}>
     */
    public function linksFor(?string $catalogSheetName, ?string $archipelagoGameName, string $name): array;
}
