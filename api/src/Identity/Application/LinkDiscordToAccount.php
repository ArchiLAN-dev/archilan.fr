<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\DiscordOAuthClientInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class LinkDiscordToAccount
{
    public function __construct(
        private DiscordOAuthClientInterface $discordClient,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private string $discordRedirectUriLink,
    ) {
    }

    /**
     * @return array{outcome: 'linked'|'discord_already_used'|'no_verified_email'|'discord_error'}
     */
    public function link(string $userId, string $code): array
    {
        $user = $this->entityManager->find(User::class, $userId);
        if (!$user instanceof User) {
            return ['outcome' => 'discord_error'];
        }

        try {
            $tokenData = $this->discordClient->exchangeCode($code, $this->discordRedirectUriLink);
            $accessToken = is_string($tokenData['access_token'] ?? null) ? $tokenData['access_token'] : '';
            if ('' === $accessToken) {
                return ['outcome' => 'discord_error'];
            }

            $discordUser = $this->discordClient->fetchUser($accessToken);
        } catch (\Throwable) {
            return ['outcome' => 'discord_error'];
        }

        $discordId = is_string($discordUser['id'] ?? null) ? $discordUser['id'] : '';
        $discordUsername = is_string($discordUser['username'] ?? null) ? $discordUser['username'] : '';
        $verified = true === ($discordUser['verified'] ?? null);

        if ('' === $discordId || !$verified) {
            return ['outcome' => 'no_verified_email'];
        }

        $now = new \DateTimeImmutable();
        $previousDiscordId = $user->getDiscordId();
        $user->linkDiscord($discordId, $discordUsername, $now);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return ['outcome' => 'discord_already_used'];
        }

        $this->logger->info('discord.linked', ['userId' => $user->getId()]);

        if (null !== $previousDiscordId && $previousDiscordId !== $discordId) {
            $this->dispatchDiscordSync(new SyncDiscordRoleMessage(
                $user->getId(),
                $previousDiscordId,
                [],
                removeAll: true,
            ));
        }

        $this->dispatchDiscordSync(new SyncDiscordRoleMessage(
            $user->getId(),
            $discordId,
            $user->getRoles(),
        ));

        return ['outcome' => 'linked'];
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
