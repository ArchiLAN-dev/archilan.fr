<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class ResetPassword
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
        private RefreshTokenRepository $refreshTokenRepository,
    ) {
    }

    public function reset(string $rawToken, string $newPassword, \DateTimeImmutable $now): void
    {
        $hash = hash('sha256', $rawToken);
        $token = $this->entityManager->getRepository(PasswordResetToken::class)->findOneBy(['tokenHash' => $hash]);

        if (!$token instanceof PasswordResetToken || !$token->isValid($now)) {
            throw new \InvalidArgumentException('Invalid or expired password reset token.');
        }

        $user = $this->entityManager->getRepository(User::class)->find($token->getUserId());

        if (!$user instanceof User || $user->isDeleted()) {
            throw new \InvalidArgumentException('Invalid or expired password reset token.');
        }

        $newHash = $this->hasher->hashPassword($user, $newPassword);
        $user->resetPassword($newHash, $now);
        $token->markUsed($now);

        $this->entityManager->flush();

        $this->refreshTokenRepository->revokeAllForUser($user->getId());
    }
}
