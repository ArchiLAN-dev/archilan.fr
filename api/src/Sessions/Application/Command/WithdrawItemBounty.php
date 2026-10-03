<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Entity\ItemBounty;
use App\Sessions\Domain\Repository\ItemBountyRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Psr\Clock\ClockInterface;

/**
 * The poster takes back an open bounty (story 41.4): the whole amount comes back, no commission.
 */
final readonly class WithdrawItemBounty
{
    public function __construct(
        private ItemBountyRepositoryInterface $bounties,
        private RecordPelleMovement $record,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException  when the bounty does not exist
     * @throws ForbiddenException when it is someone else's
     * @throws ConflictException  when it is no longer open
     */
    public function withdraw(string $userId, string $bountyId): void
    {
        $bounty = $this->bounties->findById($bountyId);
        if (!$bounty instanceof ItemBounty) {
            throw new NotFoundException('Prime introuvable.', 'bounty_not_found');
        }
        if ($bounty->getPosterId() !== $userId) {
            throw new ForbiddenException('Seul son auteur peut retirer une prime.', 'forbidden');
        }
        if (!$bounty->isOpen()) {
            throw new ConflictException('Cette prime est déjà réglée.', 'bounty_not_open');
        }

        $now = $this->clock->now();
        $this->record->record(
            new RecordPelleMovementInput(
                $userId, $bounty->getAmount(), PelleKind::Gold, null, PelleReason::BountyRefund,
                sprintf('Prime retirée : %s', $bounty->getItemName()), null,
                sprintf('bounty-refund:%s', $bounty->getId()),
                byAdmin: true,
            ),
            function () use ($bounty, $now): void {
                $bounty->withdraw($now);
                $this->bounties->save($bounty);
            },
        );
    }
}
