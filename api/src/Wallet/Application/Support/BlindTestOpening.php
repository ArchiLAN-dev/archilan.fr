<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/**
 * An event's blind test as {@see BlindTestGate} found it open (story 41.40): what a source is worth, the cap per
 * member, when the event ends and whether members not registered earn (story 41.54).
 */
final readonly class BlindTestOpening
{
    public function __construct(
        public int $pellesPerSource,
        public int $capPerPlayer,
        public \DateTimeImmutable $endsAt,
        public bool $pellesForUnregistered = true,
    ) {
    }
}
