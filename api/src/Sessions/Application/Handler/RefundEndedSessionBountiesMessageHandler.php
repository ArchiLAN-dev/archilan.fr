<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Sessions\Application\Command\RefundEndedSessionBounties;
use App\Sessions\Application\Message\RefundEndedSessionBountiesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefundEndedSessionBountiesMessageHandler
{
    public function __construct(
        private RefundEndedSessionBounties $refund,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefundEndedSessionBountiesMessage $message): void
    {
        $refunded = $this->refund->refundEnded();
        if ($refunded > 0) {
            $this->logger->info('sessions.bounty.refunded_at_end', ['bounties' => $refunded]);
        }
    }
}
