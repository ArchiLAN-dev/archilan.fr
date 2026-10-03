<?php

declare(strict_types=1);

namespace App\Wallet\Application\Handler;

use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Application\Message\AwardWeeklyQuestsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AwardWeeklyQuestsMessageHandler
{
    public function __construct(
        private AwardWeeklyQuests $award,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AwardWeeklyQuestsMessage $message): void
    {
        $paid = $this->award->award();
        if ($paid > 0) {
            $this->logger->info('wallet.quests.paid', ['quests' => $paid]);
        }
    }
}
