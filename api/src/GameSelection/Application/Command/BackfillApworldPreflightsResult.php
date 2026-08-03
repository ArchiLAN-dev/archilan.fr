<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Outcome of the apworld preflight backfill sweep (story 9.38 AC5).
 */
final readonly class BackfillApworldPreflightsResult
{
    public function __construct(
        public int $total,
        public int $requested,
        public int $skipped,
        public int $failed,
    ) {
    }
}
