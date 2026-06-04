<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;

final readonly class ResendEmailConfirmation
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private SendEmailConfirmation $sendEmailConfirmation,
    ) {
    }

    public function resend(string $userId, \DateTimeImmutable $now): void
    {
        $user = $this->userRepository->findById($userId);

        if (!$user instanceof User || $user->isDeleted() || $user->isEmailVerified()) {
            return;
        }

        $this->sendEmailConfirmation->sendFor($userId, $user->getEmail(), $user->getDisplayName(), $now);
    }
}
