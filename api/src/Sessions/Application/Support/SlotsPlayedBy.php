<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;

/**
 * The slots of a session a member actually plays: the ones they declared, plus the ones they were
 * added to as a co-player (story 16.17).
 *
 * Three authorization paths used to answer that question by comparing `SessionSlot.registrationId`
 * themselves - the patch download, the event connection details and the hint/item guard. Answering
 * it in one place is what keeps a co-player from being half-recognised: allowed to see their hints
 * but not to download their patch, or the reverse.
 *
 * The owner key is not the member id on both surfaces: `registrationId` holds the member id on a
 * personal run and the registration id on an event session. Callers resolve it and pass it in;
 * co-players are always keyed by member id.
 */
final readonly class SlotsPlayedBy
{
    public function __construct(
        private SessionSlotRepositoryInterface $slots,
        private SlotCoPlayerRepositoryInterface $coPlayers,
    ) {
    }

    /**
     * @param string|null $ownerKey the caller's `registrationId` value for this surface, or null
     *                              when they own nothing in the session
     *
     * @return list<SessionSlot> ordered by slot order, without duplicates
     */
    public function inSession(string $sessionId, ?string $ownerKey, string $userId): array
    {
        /** @var array<string, SessionSlot> $found */
        $found = [];

        if (null !== $ownerKey && '' !== $ownerKey) {
            foreach ($this->slots->findByRegistrationAndSession($ownerKey, $sessionId) as $slot) {
                $found[$slot->getId()] = $slot;
            }
        }

        $coPlayed = $this->coPlayers->findSlotIdsForUser($userId);
        if ([] !== $coPlayed) {
            foreach ($this->slots->findBySessionId($sessionId) as $slot) {
                $gameSlotId = $slot->getSlotId();
                if (null !== $gameSlotId && in_array($gameSlotId, $coPlayed, true)) {
                    $found[$slot->getId()] = $slot;
                }
            }
        }

        $ordered = array_values($found);
        usort($ordered, static fn (SessionSlot $a, SessionSlot $b): int => $a->getSlotOrder() <=> $b->getSlotOrder());

        return $ordered;
    }

    /**
     * Does this member play that one slot? Same rule as inSession(), for the guard that already
     * resolved a single slot and only needs a yes or no.
     */
    public function plays(SessionSlot $slot, ?string $ownerKey, string $userId): bool
    {
        if (null !== $ownerKey && '' !== $ownerKey && $slot->getRegistrationId() === $ownerKey) {
            return true;
        }

        $gameSlotId = $slot->getSlotId();

        return null !== $gameSlotId && in_array($gameSlotId, $this->coPlayers->findSlotIdsForUser($userId), true);
    }
}
