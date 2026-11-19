<?php

declare(strict_types=1);

namespace App\Trackers\Application\Query;

/** A PopTracker pack the catalog names for a game and that the game does not have yet (story 54.12). */
final readonly class CatalogTrackerPackSuggestion
{
    public function __construct(
        public string $label,
        public string $url,
        /** null when the link cannot be imported (not a GitHub repository): shown, to fetch by hand */
        public ?string $importUrl,
        public ?string $repository,
    ) {
    }
}
