<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** The outcome of {@see DistributeEventPelles}: who got pelles, who was skipped, who already had them. */
final readonly class EventPelleDistribution
{
    public function __construct(
        public int $credited,
        public int $skipped,
        public int $alreadyCredited,
    ) {
    }
}
