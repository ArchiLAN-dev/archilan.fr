<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Application\Port\DiscordOAuthClientInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class LinkDiscordToAccount
{
    public function __construct(
        private DiscordOAuthClientInterface $discordClient,
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private string $discordRedirectUriLink,
    ) {
    }

    public function link(string $userId, string $code): DiscordLinkOutcome
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User) {
            return DiscordLinkOutcome::DiscordError;
        }

        try {
            $tokenData = $this->discordClient->exchangeCode($code, $this->discordRedirectUriLink);
            $accessToken = is_string($tokenData['access_token'] ?? null) ? $tokenData['access_token'] : '';
            if ('' === $accessToken) {
                return DiscordLinkOutcome::DiscordError;
            }

            $discordUser = $this->discordClient->fetchUser($accessToken);
        } catch (\Throwable) {
            return DiscordLinkOutcome::DiscordError;
        }

        $discordId = is_string($discordUser['id'] ?? null) ? $discordUser['id'] : '';
        $discordUsername = is_string($discordUser['username'] ?? null) ? $discordUser['username'] : '';
        $verified = true === ($discordUser['verified'] ?? null);

        if ('' === $discordId || !$verified) {
            return DiscordLinkOutcome::NoVerifiedEmail;
        }

        $now = $this->clock->now();
        $previousDiscordId = $user->getDiscordId();
        $user->linkDiscord($discordId, $discordUsername, $now);

        try {
            $this->userRepository->save($user);
        } catch (UniqueConstraintViolationException) {
            return DiscordLinkOutcome::DiscordAlreadyUsed;
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

        return DiscordLinkOutcome::Linked;
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
