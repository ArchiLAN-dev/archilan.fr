<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\PersonalRuns\Application\Command\SyncRunListingsToDiscord;
use App\PersonalRuns\Application\Message\SyncRunListingsToDiscordMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 43.27. The single worker consumes the scheduled pass and the dispatched ones one after the other, so two
 * passes never post the same listing twice.
 */
#[AsMessageHandler]
final readonly class SyncRunListingsToDiscordHandler
{
    public function __construct(
        private SyncRunListingsToDiscord $sync,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncRunListingsToDiscordMessage $message): void
    {
        $result = $this->sync->sync();
        if ($result->posted + $result->edited + $result->deleted + $result->failed > 0) {
            $this->logger->info('personal_run.listing_discord.synced', [
                'posted' => $result->posted,
                'edited' => $result->edited,
                'deleted' => $result->deleted,
                'failed' => $result->failed,
            ]);
        }
    }
}
