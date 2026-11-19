<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** A game the catalog names an importable pack for, not imported yet (story 54.12). */
final readonly class CatalogTrackerPackGame
{
    /** @param list<CatalogTrackerPackSuggestion> $suggestions */
    public function __construct(
        public string $gameId,
        public string $gameName,
        public string $gameSlug,
        public array $suggestions,
    ) {
    }
}
