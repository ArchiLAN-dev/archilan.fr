<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\ExtendDiscordTimeoutsMessage;
use App\Community\Application\Port\DiscordServerSanctionsInterface;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Support\DiscordTimeoutWindow;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Keeps a suspension longer than 28 days going on Discord (story 39.6): every night, each member still
 * suspended on the site is timed out again until the end of the suspension, or for 28 days at most. Nothing is
 * stored: a timeout posed again is the same timeout, so the pass is safe to repeat.
 *
 * Runs on the scheduler, which never retries: a member in error is logged and caught up the next night, and
 * never stops the others.
 */
#[AsMessageHandler]
final readonly class ExtendDiscordTimeoutsHandler
{
    public function __construct(
        private MemberModerationGatewayInterface $members,
        private DiscordServerSanctionsInterface $server,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExtendDiscordTimeoutsMessage $message): void
    {
        if (!$this->server->isConfigured()) {
            return;
        }

        $now = $this->clock->now();
        foreach ($this->members->currentlySuspended($now) as $member) {
            if (null === $member->discordId) {
                continue;
            }
            try {
                $this->server->timeout($member->discordId, DiscordTimeoutWindow::until($member->suspendedUntil, $now), $member->reason ?? 'Suspension');
            } catch (\Throwable $e) {
                $this->logger->warning('moderation_server.timeout_not_extended', [
                    'userId' => $member->userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
