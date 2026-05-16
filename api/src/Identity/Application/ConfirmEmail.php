<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConfirmEmail
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function confirm(string $rawToken, \DateTimeImmutable $now): string
    {
        $token = $this->entityManager
            ->getRepository(EmailConfirmationToken::class)
            ->findOneBy(['tokenHash' => hash('sha256', $rawToken)]);

        if (!$token instanceof EmailConfirmationToken || !$token->isValid($now)) {
            return 'invalid';
        }

        $user = $this->entityManager->find(User::class, $token->getUserId());

        if (!$user instanceof User) {
            return 'invalid';
        }

        $token->markConfirmed($now);
        $user->confirmEmail($now);
        $this->entityManager->flush();

        return 'confirmed';
    }
}
