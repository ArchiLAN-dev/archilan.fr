<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Communications\Application\PasswordResetMessage;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\PasswordResetTokenRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RequestPasswordReset
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private PasswordResetTokenRepositoryInterface $tokenRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function request(string $email, \DateTimeImmutable $now): void
    {
        $canonical = mb_strtolower(trim($email));
        $user = $this->userRepository->findByEmailCanonical($canonical);

        if (null === $user || $user->isDeleted()) {
            return;
        }

        $this->tokenRepository->revokeExistingForUser($user->getId(), $now);

        $rawToken = bin2hex(random_bytes(32));
        $token = PasswordResetToken::issue($user->getId(), $rawToken, $now);
        $this->tokenRepository->save($token);

        $this->messageBus->dispatch(new PasswordResetMessage(
            userEmail: $user->getEmail(),
            userDisplayName: $user->getDisplayName(),
            rawToken: $rawToken,
            expiresAt: $token->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ));
    }
}
