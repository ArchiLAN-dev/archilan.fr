<?php

declare(strict_types=1);

namespace App\Communications\Application\Handler;

use App\Communications\Application\Email\EmailConfirmationEmail;
use App\Communications\Application\Message\EmailConfirmationMessage;
use App\Communications\Application\Service\ArchilanMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EmailConfirmationHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private string $siteUrl,
    ) {
    }

    public function __invoke(EmailConfirmationMessage $message): void
    {
        $confirmationUrl = rtrim($this->siteUrl, '/').'/confirmation-email?token='.urlencode($message->rawToken);

        $this->mailer->send(new EmailConfirmationEmail(
            $message->userEmail,
            $message->userDisplayName,
            $confirmationUrl,
            $this->siteUrl,
        ));
    }
}
