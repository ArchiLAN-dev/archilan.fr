<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

/** What one pass of {@see SyncRunListingsToDiscord} did (story 43.27). */
final readonly class RunListingsSyncResult
{
    public function __construct(
        public int $posted,
        public int $edited,
        public int $deleted,
        public int $failed,
    ) {
    }
}
