<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\PasseurBridgeInterface;
use App\Sessions\Application\Support\SlotAbsence;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SlotBlockEpisodeRepositoryInterface;
use App\Sessions\Domain\Service\PasseurSelection;
use App\Sessions\Domain\ValueObject\PasseurItem;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use Psr\Clock\ClockInterface;

/**
 * Story 52.3: for a blocked slot, the chests of absent worlds that hold what it waits for, best first.
 * Read only: nothing is opened, nothing is paid.
 */
final readonly class PasseurCandidatesQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private RunRepositoryInterface $runs,
        private SlotBlockEpisodeRepositoryInterface $episodes,
        private SlotAbsence $absence,
        private PasseurBridgeInterface $bridge,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException           when the session does not exist
     * @throws ServiceUnavailableException when the bridge cannot list the slot's items
     */
    public function forSlot(string $sessionId, int $slotIndex): PasseurCandidates
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            throw new NotFoundException('Session introuvable.');
        }

        // Decision 0: the Passeur only exists for a slot in BK.
        $blockedSince = null;
        foreach ($this->episodes->findBySessionId($sessionId) as $episode) {
            if ((string) $slotIndex === $episode->getSlotIndex()) {
                $blockedSince = $episode->getBlockedSince();
            }
        }
        if (null === $blockedSince) {
            return PasseurCandidates::notBlocked();
        }

        $absentSlots = $this->absence->absentSlots($session, $this->runs->findBySessionId($sessionId), $this->clock->now());
        $items = [] === $absentSlots ? [] : $this->bridge->items($sessionId, $slotIndex);
        if (null === $items) {
            throw new ServiceUnavailableException('Le bridge ne répond pas.', 'bridge_unavailable');
        }

        $candidates = PasseurSelection::candidates($items, $absentSlots);
        $gains = [] === $candidates ? null : $this->bridge->gains($sessionId, $slotIndex, array_slice($candidates, 0, PasseurBridgeInterface::MAX_MEASURED));
        $ranked = null === $gains ? $candidates : PasseurSelection::byRank($candidates, $gains);

        return new PasseurCandidates(
            $blockedSince,
            $absentSlots,
            null === $gains ? PasseurCandidates::RANKING_SPHERE : PasseurCandidates::RANKING_EXACT,
            array_map(static fn (PasseurItem $item): PasseurCandidate => new PasseurCandidate($item, $gains[$item->key()] ?? null), $ranked),
        );
    }
}
