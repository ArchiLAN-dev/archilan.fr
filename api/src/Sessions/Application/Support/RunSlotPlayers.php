<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;

/**
 * Who plays a slot of a private run's session (story 41.37): its owner - `registrationId` holds the member id on a
 * personal run's session - and its co-players (story 16.17). An unassigned slot has an empty owner and no one.
 */
final readonly class RunSlotPlayers
{
    public function __construct(
        private SessionSlotRepositoryInterface $slots,
        private SlotCoPlayerRepositoryInterface $coPlayers,
    ) {
    }

    /**
     * @return list<string>
     */
    public function ofSlot(SessionSlot $slot): array
    {
        return $this->players([$slot]);
    }

    /**
     * @return list<string>
     */
    public function ofSession(string $sessionId): array
    {
        return $this->players($this->slots->findBySessionId($sessionId));
    }

    /**
     * @param list<SessionSlot> $slots
     *
     * @return list<string>
     */
    private function players(array $slots): array
    {
        $players = [];
        $gameSlotIds = [];
        foreach ($slots as $slot) {
            if ('' !== $slot->getRegistrationId()) {
                $players[] = $slot->getRegistrationId();
            }
            if (null !== $slot->getSlotId() && '' !== $slot->getSlotId()) {
                $gameSlotIds[] = $slot->getSlotId();
            }
        }
        if ([] !== $gameSlotIds) {
            foreach ($this->coPlayers->findBySlotIds(array_values(array_unique($gameSlotIds))) as $coPlayer) {
                $players[] = $coPlayer->getUserId();
            }
        }

        return array_values(array_unique($players));
    }
}
