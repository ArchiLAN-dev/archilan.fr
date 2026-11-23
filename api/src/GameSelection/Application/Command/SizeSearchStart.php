<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/** Story 56.7: the outcome of starting a size search, and its id when it started. */
final readonly class SizeSearchStart
{
    public function __construct(
        public SizeSearchOutcome $outcome,
        public ?string $searchId = null,
    ) {
    }
}
