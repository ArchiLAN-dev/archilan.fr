<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Domain\ValueObject\PasseurItem;

/**
 * Story 52.3: a chest the Passeur may open, with the buyer's locations it makes reachable (null: not measured).
 */
final readonly class PasseurCandidate
{
    public function __construct(
        public PasseurItem $item,
        public ?int $unlocks,
    ) {
    }
}
