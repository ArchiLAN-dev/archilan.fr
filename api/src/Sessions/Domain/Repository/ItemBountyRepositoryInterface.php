<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\ItemBounty;

interface ItemBountyRepositoryInterface
{
    public function findById(string $id): ?ItemBounty;

    /**
     * The open bounty of a slot for an item, the oldest first if several were somehow posted; item names compare
     * regardless of case, as Archipelago names do in the feed.
     */
    public function findOpenFor(string $sessionId, string $slotName, string $itemName): ?ItemBounty;

    /**
     * @return list<ItemBounty>
     */
    public function findOpenBySession(string $sessionId): array;

    /**
     * Open bounties whose session is over (finished, stopped, failed, crashed).
     *
     * @return list<ItemBounty>
     */
    public function findOpenOfEndedSessions(): array;

    public function save(ItemBounty $bounty): void;

    /**
     * Story 41.39: locks the bounty's row for the current transaction and says whether it is still open. Called by
     * whatever settles a bounty, inside the transaction of its movement: a second settlement running at the same
     * time waits for the first, then finds the bounty closed and rolls back, instead of paying it a second time.
     */
    public function claimOpen(string $bountyId): bool;
}
