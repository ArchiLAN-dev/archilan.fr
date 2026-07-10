<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class UnlinkDiscordFromAccount
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function unlink(string $userId): void
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User || !$user->isDiscordLinked()) {
            return;
        }

        $discordId = $user->getDiscordId();
        $user->unlinkDiscord($this->clock->now());
        $this->userRepository->save($user);

        $this->logger->info('discord.unlinked', ['userId' => $user->getId()]);

        if (null !== $discordId) {
            $this->dispatchDiscordSync(new SyncDiscordRoleMessage(
                $user->getId(),
                $discordId,
                [],
                removeAll: true,
            ));
        }
    }

    private function dispatchDiscordSync(SyncDiscordRoleMessage $message): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('discord.sync_dispatch_failed', [
                'userId' => $message->userId,
                'discordUserId' => $message->discordUserId,
                'removeAll' => $message->removeAll,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
