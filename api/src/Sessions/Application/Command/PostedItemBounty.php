<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Entity\ItemBounty;

/** The bounty {@see PostItemBounty} holds open: what the sender of the item would get. */
final readonly class PostedItemBounty
{
    public function __construct(
        public string $id,
        public string $slotName,
        public string $itemName,
        public int $amount,
        public int $reward,
    ) {
    }

    public static function of(ItemBounty $bounty): self
    {
        return new self($bounty->getId(), $bounty->getSlotName(), $bounty->getItemName(), $bounty->getAmount(), $bounty->reward());
    }
}
