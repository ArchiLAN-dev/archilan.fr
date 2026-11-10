<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\PasseurTermsResolver;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\PasseurOpeningRepositoryInterface;
use App\Sessions\Domain\Service\PasseurPrice;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 52.4: the Passeur's proposal on a player's slot page. `enabled` false: the party has no Passeur (or is not
 * running), nothing to show; enabled without an offer: the player is not blocked, or no other world holds what
 * they wait for.
 */
final readonly class PasseurOfferQuery
{
    public function __construct(
        private PasseurTermsResolver $terms,
        private PasseurCandidatesQuery $candidates,
        private PasseurOpeningRepositoryInterface $openings,
        private PelleMovementRepositoryInterface $movements,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{enabled: bool, offer: PasseurOffer|null}
     */
    public function forPlayer(string $userId, string $sessionId, int $slotIndex): array
    {
        $terms = $this->terms->of($sessionId);
        if (null === $terms || !$terms->enabled() || Session::STATUS_RUNNING !== $terms->session->getStatus()) {
            return ['enabled' => false, 'offer' => null];
        }

        $found = $this->candidates->forSlot($sessionId, $slotIndex);
        if (null === $found->blockedSince) {
            return ['enabled' => true, 'offer' => null];
        }

        $now = $this->clock->now();
        [$dayStart, $dayEnd] = PasseurPrice::dayOf($now);
        $config = $terms->config;
        foreach ($found->candidates as $candidate) {
            $item = $candidate->item;
            if ($this->openings->countForWorld($sessionId, $item->findingSlot, $dayStart, $dayEnd) >= $config->passeurDailyLimit) {
                continue;
            }

            $opensAt = $terms->opensAt($found->blockedSince);
            $waiting = $now < $opensAt;
            $price = PasseurPrice::at($config->passeurBasePrice, $config->passeurDecayPercent, $config->passeurFloorPercent, $opensAt, $now);
            $floor = PasseurPrice::at($config->passeurBasePrice, $config->passeurDecayPercent, $config->passeurFloorPercent, $opensAt, $now->modify('+100 years'));
            $steps = intdiv(max(0, $now->getTimestamp() - $opensAt->getTimestamp()), PasseurPrice::STEP_SECONDS);

            return ['enabled' => true, 'offer' => new PasseurOffer(
                $item->findingSlot,
                $item->findingPlayerName,
                $item->locationId,
                $item->locationName,
                $item->itemName,
                $price,
                $config->passeurBasePrice,
                $terms->kind,
                $this->movements->balance($userId, $terms->pelleKind(), $terms->event?->getId(), $terms->run?->getId()),
                $found->blockedSince,
                !$waiting && $price > $floor ? $opensAt->modify(sprintf('+%d seconds', ($steps + 1) * PasseurPrice::STEP_SECONDS)) : null,
                $waiting ? $opensAt : null,
            )];
        }

        return ['enabled' => true, 'offer' => null];
    }
}
