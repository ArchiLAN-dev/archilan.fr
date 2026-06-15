<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\GameSelection\Domain\SteamProfileReference;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class SaveSteamAccount
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{outcome: 'saved'|'invalid_input'|'not_found'}
     */
    public function save(string $userId, string $rawInput): array
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User) {
            return ['outcome' => 'not_found'];
        }

        if (null === SteamProfileReference::parse($rawInput)) {
            return ['outcome' => 'invalid_input'];
        }

        $user->setSteamProfile(trim($rawInput));
        $this->userRepository->save($user);

        $this->logger->info('steam.account_saved', ['userId' => $user->getId()]);

        return ['outcome' => 'saved'];
    }

    public function remove(string $userId): void
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User) {
            return;
        }

        $user->setSteamProfile(null);
        $this->userRepository->save($user);

        $this->logger->info('steam.account_removed', ['userId' => $user->getId()]);
    }
}
