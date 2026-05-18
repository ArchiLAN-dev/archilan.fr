<?php

declare(strict_types=1);

namespace App\Identity\Application\Message;

use App\Identity\Application\DiscordBotClientInterface;
use App\Identity\Domain\User;
use App\Membership\Application\ActiveMembershipQueryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncDiscordRoleMessageHandler
{
    public function __construct(
        private DiscordBotClientInterface $discordBotClient,
        private EntityManagerInterface $entityManager,
        private ActiveMembershipQueryInterface $membershipQuery,
        private LoggerInterface $logger,
        private string $guildId,
        private string $roleIdAdmin,
        private string $roleIdMember,
    ) {
    }

    public function __invoke(SyncDiscordRoleMessage $message): void
    {
        $user = $this->entityManager->find(User::class, $message->userId);
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

                // Member role: active membership record OR manually-granted ROLE_MEMBER
                $isMember = \in_array('ROLE_MEMBER', $roles, true)
                    || $this->membershipQuery->hasActiveMembership($message->userId);

                $this->syncRole($message->discordUserId, $this->roleIdMember, $isMember);
            }

            $this->logger->info('discord_bot.role_synced', [
                'userId' => $message->userId,
                'discordUserId' => $message->discordUserId,
                'removeAll' => $message->removeAll,
            ]);

            if ($user instanceof User && $user->getDiscordId() === $message->discordUserId) {
                $user->markDiscordSyncSuccess(new \DateTimeImmutable());
                $this->entityManager->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('discord_bot.role_sync_failed', [
                'userId' => $message->userId,
                'discordUserId' => $message->discordUserId,
                'error' => $e->getMessage(),
            ]);

            if ($user instanceof User && $user->getDiscordId() === $message->discordUserId) {
                $user->markDiscordSyncFailure($e->getMessage(), new \DateTimeImmutable());
                $this->entityManager->flush();
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
