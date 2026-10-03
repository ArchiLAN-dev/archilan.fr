<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Repository\ItemBountyRepositoryInterface;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The open bounties of a session that is over go back to their posters (story 41.4): an item sent while the bridge
 * was down never leaves pelles stuck in a bounty.
 */
final readonly class RefundEndedSessionBounties
{
    public function __construct(
        private ItemBountyRepositoryInterface $bounties,
        private RecordPelleMovement $record,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return int the number of bounties refunded */
    public function refundEnded(): int
    {
        $refunded = 0;
        foreach ($this->bounties->findOpenOfEndedSessions() as $bounty) {
            $now = $this->clock->now();
            try {
                $this->record->record(
                    new RecordPelleMovementInput(
                        $bounty->getPosterId(), $bounty->getAmount(), PelleKind::Gold, null, PelleReason::BountyRefund,
                        sprintf('Prime rendue : fin de partie (%s)', $bounty->getItemName()), null,
                        sprintf('bounty-refund:%s', $bounty->getId()),
                        byAdmin: true,
                    ),
                    function () use ($bounty, $now): void {
                        $bounty->refund($now);
                        $this->bounties->save($bounty);
                    },
                );
                ++$refunded;
            } catch (\Throwable $e) {
                $this->logger->warning('sessions.bounty.refund_failed', ['bountyId' => $bounty->getId(), 'error' => $e->getMessage()]);
            }
        }

        return $refunded;
    }
}
