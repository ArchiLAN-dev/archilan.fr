<?php

declare(strict_types=1);

namespace App\Wallet\Application\Handler;

use App\Wallet\Application\Command\CollectMinimapperzGames;
use App\Wallet\Application\Message\CollectMinimapperzGamesMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CollectMinimapperzGamesMessageHandler
{
    public function __construct(
        private CollectMinimapperzGames $collect,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CollectMinimapperzGamesMessage $message): void
    {
        $result = $this->collect->collectAll();
        if ($result->credited > 0) {
            $this->logger->info('wallet.minimapperz.collected', ['events' => $result->events, 'games' => $result->games, 'credited' => $result->credited]);
        }
    }
}
