<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

/**
 * A game whose tracked GitHub source has a newer apworld than the deployed one (story 38.5). Carries
 * what the automatic update of story 38.6 needs, so it does not read the catalogue again.
 */
final readonly class ApworldUpdateAvailable
{
    public function __construct(
        public string $gameId,
        public string $gameName,
        public string $latestTag,
        public ?string $assetName,
        public ?string $assetDownloadUrl,
    ) {
    }
}
