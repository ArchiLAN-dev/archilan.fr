<?php

declare(strict_types=1);

namespace App\Identity\Application\Handler;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Application\Port\DiscordBotClientInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncDiscordRoleMessageHandler
{
    public function __construct(
        private DiscordBotClientInterface $discordBotClient,
        private UserRepositoryInterface $userRepository,
        private ActiveMembershipQueryInterface $membershipQuery,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private string $guildId,
        private string $roleIdAdmin,
        private string $roleIdMember,
    ) {
    }

    public function __invoke(SyncDiscordRoleMessage $message): void
    {
        $user = $this->userRepository->findById($message->userId);
        if (!$message->removeAll && !$user instanceof User) {
            $this->logger->warning('discord_bot.role_sync_skipped_user_missing', [
                'userId' => $message->userId,
                'discordUserId' => $message->discordUserId,
            ]);

            return;
        }

        if (!$message->removeAll && $user instanceof User && $user->getDiscordId() !== $message->discordUserId) {
            $this->logger->info('discord_bot.role_sync_skipped_stale_message', [
                'userId' => $message->userId,
                'messageDiscordUserId' => $message->discordUserId,
                'currentDiscordUserId' => $user->getDiscordId(),
            ]);

            return;
        }

        try {
            if ($message->removeAll) {
                foreach ([$this->roleIdAdmin, $this->roleIdMember] as $roleId) {
                    if ('' !== $roleId) {
                        $this->discordBotClient->removeRole($this->guildId, $message->discordUserId, $roleId);
                    }
                }
            } else {
                $roles = $user instanceof User ? $user->getRoles() : $message->archilanRoles;

                $this->syncRole(
                    $message->discordUserId,
                    $this->roleIdAdmin,
                    \in_array('ROLE_ADMIN', $roles, true),
                );

                // The live membership decides: ROLE_MEMBER outlives it, and the sync that follows an expiry
                // would then keep the Discord role forever (story 50.3).
                $this->syncRole(
                    $message->discordUserId,
                    $this->roleIdMember,
                    $this->membershipQuery->hasActiveMembership($message->userId),
                );
            }

            $this->logger->info('discord_bot.role_synced', [
                'userId' => $message->userId,
                'discordUserId' => $message->discordUserId,
                'removeAll' => $message->removeAll,
            ]);

            if ($user instanceof User && $user->getDiscordId() === $message->discordUserId) {
                $user->markDiscordSyncSuccess($this->clock->now());
                $this->userRepository->save($user);
            }
        } catch (\Throwable $e) {
            $this->logger->error('discord_bot.role_sync_failed', [
                'userId' => $message->userId,
                'discordUserId' => $message->discordUserId,
                'error' => $e->getMessage(),
            ]);

            if ($user instanceof User && $user->getDiscordId() === $message->discordUserId) {
                $user->markDiscordSyncFailure($e->getMessage(), $this->clock->now());
                $this->userRepository->save($user);
            }

            throw $e;
        }
    }

    private function syncRole(string $discordUserId, string $roleId, bool $shouldHave): void
    {
        if ('' === $roleId) {
            return;
        }

        if ($shouldHave) {
            $this->discordBotClient->assignRole($this->guildId, $discordUserId, $roleId);
        } else {
            $this->discordBotClient->removeRole($this->guildId, $discordUserId, $roleId);
        }
    }
}
