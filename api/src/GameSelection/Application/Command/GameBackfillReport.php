<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Report of a game-catalogue backfill run, shared by the option-types / platforms / steam-app-id backfills
 * ({@see BackfillGameOptionTypes}, {@see BackfillGamePlatforms}, {@see BackfillSteamAppIds}). The console
 * command prints the counts.
 */
final readonly class GameBackfillReport
{
    public function __construct(
        public int $processed,
        public int $updated,
    ) {
    }
}
