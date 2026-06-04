<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailConfirmationTokenRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;

final readonly class ConfirmEmail
{
    public function __construct(
        private EmailConfirmationTokenRepositoryInterface $tokenRepository,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function confirm(string $rawToken, \DateTimeImmutable $now): string
    {
        $token = $this->tokenRepository->findByTokenHash(hash('sha256', $rawToken));

        if (null === $token || !$token->isValid($now)) {
            return 'invalid';
        }

        $user = $this->userRepository->findById($token->getUserId());

        if (null === $user) {
            return 'invalid';
        }

        $token->markConfirmed($now);
        $user->confirmEmail($now);

        // Persist both then flush once through the user repository
        $this->tokenRepository->save($token);
        $this->userRepository->save($user);

        return 'confirmed';
    }
}
