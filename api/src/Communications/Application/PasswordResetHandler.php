<?php

declare(strict_types=1);

namespace App\Communications\Application;

use App\Communications\Application\Email\PasswordResetEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PasswordResetHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private string $siteUrl,
    ) {
    }

    public function __invoke(PasswordResetMessage $message): void
    {
        $resetUrl = rtrim($this->siteUrl, '/').'/reinitialisation-mot-de-passe?token='.urlencode($message->rawToken);

        $this->mailer->send(new PasswordResetEmail(
            $message->userEmail,
            $message->userDisplayName,
            $resetUrl,
            $this->siteUrl,
        ));
    }
}
