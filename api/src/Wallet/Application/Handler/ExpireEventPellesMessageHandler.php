<?php

declare(strict_types=1);

namespace App\Wallet\Application\Handler;

use App\Wallet\Application\Command\ExpireEventPelles;
use App\Wallet\Application\Message\ExpireEventPellesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExpireEventPellesMessageHandler
{
    public function __construct(
        private ExpireEventPelles $expire,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExpireEventPellesMessage $message): void
    {
        $result = $this->expire->expireEnded();
        if ($result->members > 0) {
            $this->logger->info('wallet.event_pelles.expired', ['members' => $result->members, 'converted' => $result->converted, 'destroyed' => $result->destroyed]);
        }
    }
}
