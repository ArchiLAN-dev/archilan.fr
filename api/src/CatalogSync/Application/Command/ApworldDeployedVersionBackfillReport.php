<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Command;

final readonly class ApworldDeployedVersionBackfillReport
{
    /**
     * @param list<string> $unmatchedGames
     */
    public function __construct(
        public int $matched,
        public int $unmatched,
        public int $total,
        public array $unmatchedGames,
        public bool $rateLimitHit,
    ) {
    }
}
