<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\PelleHintTerms;
use App\Sessions\Domain\Repository\ItemBountyRepositoryInterface;

/**
 * The open bounties of a session, as the players of the session see them on a slot page (story 41.4): which item,
 * for which slot, how much the sender would get. No poster name, only whether the bounty is the viewer's own.
 */
final readonly class ItemBountiesQuery
{
    public function __construct(
        private PelleHintTerms $terms,
        private ItemBountyRepositoryInterface $bounties,
    ) {
    }

    /**
     * `bronze` (story 41.39): the session belongs to a private run, so a bounty posted there holds the run's bronze.
     *
     * @return array{enabled: bool, bronze: bool, bounties: list<array{id: string, slotName: string, itemName: string, amount: int, reward: int, mine: bool}>}|null
     */
    public function forSession(string $sessionId, string $viewerId): ?array
    {
        $terms = $this->terms->of($sessionId);
        if (null === $terms) {
            return null;
        }

        $bounties = [];
        foreach ($this->bounties->findOpenBySession($sessionId) as $bounty) {
            $bounties[] = [
                'id' => $bounty->getId(),
                'slotName' => $bounty->getSlotName(),
                'itemName' => $bounty->getItemName(),
                'amount' => $bounty->getAmount(),
                'reward' => $bounty->reward(),
                'mine' => $bounty->getPosterId() === $viewerId,
            ];
        }

        return ['enabled' => $terms['bounties'], 'bronze' => null !== $terms['runId'], 'bounties' => $bounties];
    }
}
