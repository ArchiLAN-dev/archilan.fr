<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Identity\Infrastructure\DiscordOAuthClientInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

final readonly class HandleDiscordAuthCallback
{
    public function __construct(
        private DiscordOAuthClientInterface $discordClient,
        private UserRepositoryInterface $userRepository,
        private SlugGenerator $slugGenerator,
        private LoggerInterface $logger,
        private string $discordRedirectUriAuth,
    ) {
    }

    /**
     * @return array{outcome: 'logged_in'|'registered', user: User}
     *                                                              |array{outcome: 'email_conflict'|'no_verified_email'|'discord_error'}
     */
    public function handle(string $code): array
    {
        try {
            $tokenData = $this->discordClient->exchangeCode($code, $this->discordRedirectUriAuth);
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
        $email = is_string($discordUser['email'] ?? null) ? $discordUser['email'] : '';
        $verified = true === ($discordUser['verified'] ?? null);

        if ('' === $discordId || '' === $email || !$verified) {
            return ['outcome' => 'no_verified_email'];
        }

        $user = $this->userRepository->findByDiscordId($discordId);
        if ($user instanceof User) {
            $this->logger->info('discord.login', ['userId' => $user->getId()]);

            return ['outcome' => 'logged_in', 'user' => $user];
        }

        $emailCanonical = mb_strtolower(trim($email));
        $existingByEmail = $this->userRepository->findByEmailCanonical($emailCanonical);
        if ($existingByEmail instanceof User) {
            return ['outcome' => 'email_conflict'];
        }

        $now = new \DateTimeImmutable();
        $slug = $this->slugGenerator->generateForUser($discordUsername ?: $emailCanonical);
        $displayName = '' !== $discordUsername ? $discordUsername : $email;

        $newUser = User::register($email, $emailCanonical, 'discord-no-password', $now, $slug, $displayName);
        $newUser->linkDiscord($discordId, $discordUsername, $now);
        $newUser->confirmEmail($now);

        try {
            $this->userRepository->save($newUser);
        } catch (UniqueConstraintViolationException) {
            return ['outcome' => 'email_conflict'];
        }

        $this->logger->info('discord.registered', ['userId' => $newUser->getId()]);

        return ['outcome' => 'registered', 'user' => $newUser];
    }
}
