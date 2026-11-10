<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Port\PasseurBridgeInterface;
use App\Sessions\Application\Support\PasseurTermsResolver;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SlotBlockEpisodeRepositoryInterface;
use App\Sessions\Domain\Service\PasseurSelection;
use App\Sessions\Domain\ValueObject\PasseurItem;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;

/**
 * Story 52.3: for a blocked slot, the chests of other worlds that hold what it waits for, best first. Story 52.9:
 * any other world, whether its player is there or not.
 * Read only: nothing is opened, nothing is paid.
 */
final readonly class PasseurCandidatesQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private PasseurTermsResolver $terms,
        private SlotBlockEpisodeRepositoryInterface $episodes,
        private PasseurBridgeInterface $bridge,
    ) {
    }

    /**
     * `$measure` asks the tracker what each candidate opens (up to MAX_MEASURED full computations): the offer and
     * the diagnostic do, a purchase only checks its chest is still a candidate.
     *
     * @throws NotFoundException           when the session does not exist
     * @throws ServiceUnavailableException when the bridge cannot list the slot's items
     */
    public function forSlot(string $sessionId, int $slotIndex, bool $measure = true): PasseurCandidates
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            throw new NotFoundException('Session introuvable.');
        }

        // Decision 0: the Passeur only exists for a slot in BK.
        $blockedSince = null;
        foreach ($this->episodes->findBySessionId($sessionId) as $episode) {
            // Story 52.4: a block suspended by a restart is not confirmed until the slot's next state.
            if ((string) $slotIndex === $episode->getSlotIndex() && !$episode->isSuspended()) {
                $blockedSince = $episode->getBlockedSince();
            }
        }
        $terms = $this->terms->of($sessionId);
        // A weekly has no Passeur, nor tracked blocks.
        if (null === $blockedSince || null === $terms) {
            return PasseurCandidates::notBlocked();
        }

        $items = $this->bridge->items($sessionId, $slotIndex);
        if (null === $items) {
            throw new ServiceUnavailableException('Le bridge ne répond pas.', 'bridge_unavailable');
        }

        $candidates = PasseurSelection::candidates($items);
        $gains = [] === $candidates || !$measure ? null : $this->bridge->gains($sessionId, $slotIndex, array_slice($candidates, 0, PasseurBridgeInterface::MAX_MEASURED));
        $ranked = null === $gains ? $candidates : PasseurSelection::byRank($candidates, $gains);

        return new PasseurCandidates(
            $blockedSince,
            null === $gains ? PasseurCandidates::RANKING_SPHERE : PasseurCandidates::RANKING_EXACT,
            array_map(static fn (PasseurItem $item): PasseurCandidate => new PasseurCandidate($item, $gains[$item->key()] ?? null), $ranked),
        );
    }
}
