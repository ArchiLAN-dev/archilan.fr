<?php

declare(strict_types=1);

namespace App\Communications\Application\Handler;

use App\Communications\Application\Email\RegistrationReleasedEmail;
use App\Communications\Application\Message\RegistrationReleasedMessage;
use App\Communications\Application\Service\ArchilanMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RegistrationReleasedHandler
{
    public function __construct(private ArchilanMailer $mailer)
    {
    }

    public function __invoke(RegistrationReleasedMessage $message): void
    {
        $this->mailer->send(new RegistrationReleasedEmail(
            $message->userEmail,
            $message->userDisplayName,
            $message->eventTitle,
            $message->eventUrl,
        ));
    }
}
