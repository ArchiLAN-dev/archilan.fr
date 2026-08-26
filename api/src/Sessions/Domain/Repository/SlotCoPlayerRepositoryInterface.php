<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SlotCoPlayer;

interface SlotCoPlayerRepositoryInterface
{
    /**
     * @param list<string> $slotIds
     *
     * @return list<SlotCoPlayer>
     */
    public function findBySlotIds(array $slotIds): array;

    /**
     * Every game slot this member co-plays. Used by the authorization paths, which then intersect
     * with the slots of the session at hand.
     *
     * @return list<string> game slot ids
     */
    public function findSlotIdsForUser(string $userId): array;

    public function persist(SlotCoPlayer $coPlayer): void;

    public function remove(SlotCoPlayer $coPlayer): void;

    public function deleteBySlotId(string $slotId): void;

    public function flush(): void;
}
