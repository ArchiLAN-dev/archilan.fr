<?php

declare(strict_types=1);

namespace App\Wallet\Application\Handler;

use App\Shared\Application\Exception\ApplicationFailure;
use App\Wallet\Application\Command\AnnounceQuestsOnDiscord;
use App\Wallet\Application\Message\AnnounceQuestsOnDiscordJob;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 41.24: tells the week's quests on Discord, once the site announced them (41.17). A failure is logged and left
 * there: a late or repeated Discord message would do more harm than a missing one, and an admin can announce it by
 * hand (story 41.26). No Discord configured, or no quest: nothing to say.
 */
#[AsMessageHandler]
final readonly class AnnounceQuestsOnDiscordJobHandler
{
    private const array SILENT = ['discord_not_configured', 'quest_week_empty', 'quest_week_not_found'];

    public function __construct(
        private AnnounceQuestsOnDiscord $announce,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AnnounceQuestsOnDiscordJob $job): void
    {
        try {
            $this->announce->announce($job->weekKey);
        } catch (ApplicationFailure $failure) {
            if (!\in_array($failure->errorCode(), self::SILENT, true)) {
                $this->logger->warning('wallet.quests.discord_failed', ['week' => $job->weekKey, 'reason' => $failure->clientMessage()]);
            }
        }
    }
}
