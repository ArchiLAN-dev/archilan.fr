<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Outcome of a catalogue-wide sync of the declared files (story 44.3).
 */
final readonly class DeclaredFilesSyncReport
{
    public function __construct(
        public int $updated,
        public int $skipped,
        public bool $runnerAvailable,
    ) {
    }
}
