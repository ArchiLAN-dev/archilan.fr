<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Communications\Application\EmailConfirmationMessage;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\EmailConfirmationTokenRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SendEmailConfirmation
{
    public function __construct(
        private EmailConfirmationTokenRepositoryInterface $tokenRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function sendFor(string $userId, string $userEmail, ?string $userDisplayName, \DateTimeImmutable $now): void
    {
        $this->tokenRepository->revokeExistingForUser($userId, $now);

        $rawToken = bin2hex(random_bytes(32));
        $token = EmailConfirmationToken::issue($userId, $rawToken, $now);
        $this->tokenRepository->save($token);

        $this->messageBus->dispatch(new EmailConfirmationMessage(
            userEmail: $userEmail,
            userDisplayName: $userDisplayName,
            rawToken: $rawToken,
            expiresAt: $token->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ));
    }
}
