<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Exception\BountyAlreadySettledException;
use App\Sessions\Application\Support\BountyPurse;
use App\Sessions\Domain\Entity\ItemBounty;
use App\Sessions\Domain\Repository\ItemBountyRepositoryInterface;
use App\Wallet\Application\Command\RecordPelleMovement;
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
        return $this->refundAll($this->bounties->findOpenOfEndedSessions());
    }

    /**
     * Story 41.38: a private run that ends gives its open bounties back before its bronze burns, whatever its
     * session's status.
     *
     * @return int the number of bounties refunded
     */
    public function refundSession(string $sessionId): int
    {
        return $this->refundAll($this->bounties->findOpenBySession($sessionId));
    }

    /**
     * @param list<ItemBounty> $bounties
     */
    private function refundAll(array $bounties): int
    {
        $refunded = 0;
        foreach ($bounties as $bounty) {
            $now = $this->clock->now();
            try {
                $this->record->record(
                    BountyPurse::movement($bounty, $bounty->getPosterId(), $bounty->getAmount(), PelleReason::BountyRefund, sprintf('Prime rendue : fin de partie (%s)', $bounty->getItemName()), sprintf('bounty-refund:%s', $bounty->getId()), true),
                    function () use ($bounty, $now): void {
                        // Story 41.39: paid or withdrawn meanwhile, it is not given back on top.
                        if (!$this->bounties->claimOpen($bounty->getId())) {
                            throw new BountyAlreadySettledException($bounty->getId());
                        }
                        $bounty->refund($now);
                        $this->bounties->save($bounty);
                    },
                );
                ++$refunded;
            } catch (BountyAlreadySettledException) {
                continue;
            } catch (\Throwable $e) {
                $this->logger->warning('sessions.bounty.refund_failed', ['bountyId' => $bounty->getId(), 'error' => $e->getMessage()]);
            }
        }

        return $refunded;
    }
}
