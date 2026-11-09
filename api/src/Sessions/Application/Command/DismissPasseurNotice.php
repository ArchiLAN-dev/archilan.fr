<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Repository\PasseurOpeningRepositoryInterface;
use Psr\Clock\ClockInterface;

/** Story 52.5: the absent player closes their notice; the next opening brings it back. */
final readonly class DismissPasseurNotice
{
    public function __construct(
        private PasseurOpeningRepositoryInterface $openings,
        private ClockInterface $clock,
    ) {
    }

    public function execute(string $sessionId, string $userId): void
    {
        $this->openings->markSeenByAbsent($sessionId, $userId, $this->clock->now());
    }
}
