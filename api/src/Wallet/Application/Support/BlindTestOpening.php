<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/**
 * An event's blind test as {@see BlindTestGate} found it open (story 41.40): what a source is worth, the cap per
 * member and when the event ends.
 */
final readonly class BlindTestOpening
{
    public function __construct(
        public int $pellesPerSource,
        public int $capPerPlayer,
        public \DateTimeImmutable $endsAt,
    ) {
    }
}
