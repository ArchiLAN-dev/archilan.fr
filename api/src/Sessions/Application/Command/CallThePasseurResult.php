<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

/**
 * Story 52.4: a chest the Passeur opened - what it cost, what the absent player got, in which pelles. `replayed`
 * says the request had already been made: paid and opened once.
 */
final readonly class CallThePasseurResult
{
    /**
     * @param 'event'|'bronze' $kind
     */
    public function __construct(
        public string $openingId,
        public string $kind,
        public int $price,
        public int $share,
        public string $itemName,
        public string $absentPlayerName,
        public bool $replayed,
    ) {
    }
}
