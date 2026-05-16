<?php

declare(strict_types=1);

namespace App\Communications\Application;

use App\Communications\Application\Email\RegistrationConfirmationEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RegistrationConfirmationHandler
{
    public function __construct(private ArchilanMailer $mailer)
    {
    }

    public function __invoke(RegistrationConfirmationMessage $message): void
    {
        $this->mailer->send(new RegistrationConfirmationEmail(
            $message->userEmail,
            $message->userDisplayName,
            $message->eventTitle,
            $message->eventStartsAt,
            $message->eventVenue,
            $message->selectedGameNames,
        ));
    }
}
