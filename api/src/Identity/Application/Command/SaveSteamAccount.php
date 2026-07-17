<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\GameSelection\Domain\ValueObject\SteamProfileReference;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Log\LoggerInterface;

final readonly class SaveSteamAccount
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws NotFoundException   when the user does not exist
     * @throws ValidationException when the Steam profile reference is not recognised
     */
    public function save(string $userId, string $rawInput): void
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User) {
            throw new NotFoundException('Compte introuvable.');
        }

        if (null === SteamProfileReference::parse($rawInput)) {
            throw new ValidationException('Profil Steam non reconnu.', [], 'steam_invalid_input');
        }

        $user->updateSteamProfile(trim($rawInput));
        $this->userRepository->save($user);

        $this->logger->info('steam.account_saved', ['userId' => $user->getId()]);
    }

    public function remove(string $userId): void
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User) {
            return;
        }

        $user->updateSteamProfile(null);
        $this->userRepository->save($user);

        $this->logger->info('steam.account_removed', ['userId' => $user->getId()]);
    }
}
