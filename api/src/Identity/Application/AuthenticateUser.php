<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class AuthenticateUser
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function authenticate(string $email, string $password): ?User
    {
        $emailCanonical = RegisterUser::canonicalizeEmail($email);

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
        try {
            $user = $this->findOrFail(User::class, $userId);
        } catch (\RuntimeException) {
            return null;
        }

        if ($user->isDeleted()) {
            return null;
        }

        return $user;
    }
}
