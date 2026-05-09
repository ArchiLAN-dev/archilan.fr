<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class AuthenticateUser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function authenticate(string $email, string $password): ?User
    {
        $emailCanonical = RegisterLambdaUser::canonicalizeEmail($email);

        if ('' === $emailCanonical || '' === $password) {
            return null;
        }

        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['emailCanonical' => $emailCanonical]);

        if (!$user instanceof User || $user->isDeleted() || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return null;
        }

        return $user;
    }

    public function findUserById(string $userId): ?User
    {
        $user = $this->entityManager->find(User::class, $userId);

        if (!$user instanceof User || $user->isDeleted()) {
            return null;
        }

        return $user;
    }
}
