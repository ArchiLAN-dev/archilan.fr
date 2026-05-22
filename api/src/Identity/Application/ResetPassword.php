<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetTokenRepositoryInterface;
use App\Identity\Domain\RefreshTokenRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class ResetPassword
{
    public function __construct(
        private PasswordResetTokenRepositoryInterface $tokenRepository,
        private UserRepositoryInterface $userRepository,
        private UserPasswordHasherInterface $hasher,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
    ) {
    }

    public function reset(string $rawToken, string $newPassword, \DateTimeImmutable $now): void
    {
        $hash = hash('sha256', $rawToken);
        $token = $this->tokenRepository->findByTokenHash($hash);

        if (null === $token || !$token->isValid($now)) {
            throw new \InvalidArgumentException('Invalid or expired password reset token.');
        }

        $user = $this->userRepository->findById($token->getUserId());

        if (null === $user || $user->isDeleted()) {
            throw new \InvalidArgumentException('Invalid or expired password reset token.');
        }

        $newHash = $this->hasher->hashPassword($user, $newPassword);
        $user->resetPassword($newHash, $now);
        $token->markUsed($now);

        $this->tokenRepository->save($token);
        $this->userRepository->save($user);

        $this->refreshTokenRepository->revokeAllForUser($user->getId());
    }
}
