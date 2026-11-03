<?php

declare(strict_types=1);

namespace App\Identity\Application\Service;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final readonly class AuthenticateUser
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
    ) {
    }

    public function authenticate(string $email, string $password): ?User
    {
        $emailCanonical = RegisterUser::canonicalizeEmail($email);

        if ('' === $emailCanonical || '' === $password) {
            return null;
        }

        $user = $this->userRepository->findByEmailCanonical($emailCanonical);

        if (!$user instanceof User || $user->isDeleted()) {
            $this->spendHashingTime($password);

            return null;
        }

        return $this->passwordHasher->isPasswordValid($user, $password) ? $user : null;
    }

    /** Story 50.3: an unknown address takes as long to refuse as a wrong password. */
    private function spendHashingTime(string $password): void
    {
        $this->passwordHasher->hashPassword(
            new class implements PasswordAuthenticatedUserInterface {
                public function getPassword(): ?string
                {
                    return null;
                }
            },
            $password,
        );
    }

    public function findUserById(string $userId): ?User
    {
        $user = $this->userRepository->findById($userId);

        // A blocked account is treated as unauthenticated on every session/token-backed request, so a
        // suspend/ban takes effect immediately without waiting for the session cookie to expire (story 30.29).
        if (!$user instanceof User || $user->isDeleted() || $user->isAccessBlocked($this->clock->now())) {
            return null;
        }

        return $user;
    }
}
