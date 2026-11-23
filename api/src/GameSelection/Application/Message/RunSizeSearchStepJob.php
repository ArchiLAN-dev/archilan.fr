<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * Story 56.7: one step of a size search - start the next generation, or read the pending one. `round` drops the
 * steps of a search the player has started again since; `polls` and `quotaWaits` bound the waiting.
 */
final readonly class RunSizeSearchStepJob
{
    public function __construct(
        public string $searchId,
        public string $round,
        public int $polls = 0,
        public int $quotaWaits = 0,
    ) {
    }
}
