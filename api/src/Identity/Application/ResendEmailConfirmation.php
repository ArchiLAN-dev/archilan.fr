<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ResendEmailConfirmation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SendEmailConfirmation $sendEmailConfirmation,
    ) {
    }

    public function resend(string $userId, \DateTimeImmutable $now): void
    {
        $user = $this->entityManager->find(User::class, $userId);

        if (!$user instanceof User || $user->isDeleted() || $user->isEmailVerified()) {
            return;
        }

        $this->sendEmailConfirmation->sendFor($userId, $user->getEmail(), $user->getDisplayName(), $now);
    }
}
