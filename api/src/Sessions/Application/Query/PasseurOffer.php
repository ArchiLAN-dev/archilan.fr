<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 52.4: what the Passeur proposes to a blocked player right now - the best chest of another world still under
 * its daily limit, at the price of the moment, with the player's balance in the pelles it takes.
 */
final readonly class PasseurOffer
{
    /**
     * @param 'event'|'bronze' $kind
     */
    public function __construct(
        public int $findingSlot,
        public string $absentPlayerName,
        public int $locationId,
        public string $locationName,
        public string $itemName,
        public int $price,
        public int $basePrice,
        public string $kind,
        public int $balance,
        public \DateTimeImmutable $blockedSince,
        public ?\DateTimeImmutable $nextDropAt,
        public ?\DateTimeImmutable $availableAt = null,
    ) {
    }
}
