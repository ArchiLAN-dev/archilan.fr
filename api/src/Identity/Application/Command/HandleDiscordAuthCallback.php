<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Port\DiscordOAuthClientInterface;
use App\Identity\Application\Support\SlugGenerator;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class HandleDiscordAuthCallback
{
    public function __construct(
        private DiscordOAuthClientInterface $discordClient,
        private UserRepositoryInterface $userRepository,
        private SlugGenerator $slugGenerator,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private string $discordRedirectUriAuth,
    ) {
    }

    public function handle(string $code): DiscordAuthResult
    {
        try {
            $tokenData = $this->discordClient->exchangeCode($code, $this->discordRedirectUriAuth);
            $accessToken = is_string($tokenData['access_token'] ?? null) ? $tokenData['access_token'] : '';
            if ('' === $accessToken) {
                return new DiscordAuthResult(DiscordAuthOutcome::DiscordError, null);
            }

            $discordUser = $this->discordClient->fetchUser($accessToken);
        } catch (\Throwable) {
            return new DiscordAuthResult(DiscordAuthOutcome::DiscordError, null);
        }

        $discordId = is_string($discordUser['id'] ?? null) ? $discordUser['id'] : '';
        $discordUsername = is_string($discordUser['username'] ?? null) ? $discordUser['username'] : '';
        $email = is_string($discordUser['email'] ?? null) ? $discordUser['email'] : '';
        $verified = true === ($discordUser['verified'] ?? null);

        if ('' === $discordId || '' === $email || !$verified) {
            return new DiscordAuthResult(DiscordAuthOutcome::NoVerifiedEmail, null);
        }

        $user = $this->userRepository->findByDiscordId($discordId);
        if ($user instanceof User) {
            $this->logger->info('discord.login', ['userId' => $user->getId()]);

            return new DiscordAuthResult(DiscordAuthOutcome::LoggedIn, $user->getId());
        }

        $emailCanonical = mb_strtolower(trim($email));
        $existingByEmail = $this->userRepository->findByEmailCanonical($emailCanonical);
        if ($existingByEmail instanceof User) {
            return new DiscordAuthResult(DiscordAuthOutcome::EmailConflict, null);
        }

        $now = $this->clock->now();
        $slug = $this->slugGenerator->generateForUser($discordUsername ?: $emailCanonical);
        $displayName = '' !== $discordUsername ? $discordUsername : $email;

        $newUser = User::register($email, $emailCanonical, 'discord-no-password', $now, $slug, $displayName);
        $newUser->linkDiscord($discordId, $discordUsername, $now);
        $newUser->confirmEmail($now);

        try {
            $this->userRepository->save($newUser);
        } catch (UniqueConstraintViolationException) {
            return new DiscordAuthResult(DiscordAuthOutcome::EmailConflict, null);
        }

        $this->logger->info('discord.registered', ['userId' => $newUser->getId()]);

        return new DiscordAuthResult(DiscordAuthOutcome::Registered, $newUser->getId());
    }
}
