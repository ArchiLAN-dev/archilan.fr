<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * What one sweep launched (story 38.9). Nothing when the runner did not say which image runs, or
 * gave no verdicts: the sweep has nothing to rank by.
 */
final readonly class SweepApworldCatalogResult
{
    /**
     * @param list<string> $launchedApworldHashes
     */
    public function __construct(
        public bool $runnerAvailable,
        public array $launchedApworldHashes = [],
        public ?string $currentImage = null,
    ) {
    }
}
