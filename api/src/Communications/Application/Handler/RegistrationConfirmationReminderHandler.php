<?php

declare(strict_types=1);

namespace App\Communications\Application\Handler;

use App\Communications\Application\Email\RegistrationConfirmationReminderEmail;
use App\Communications\Application\Message\RegistrationConfirmationReminderMessage;
use App\Communications\Application\Service\ArchilanMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RegistrationConfirmationReminderHandler
{
    public function __construct(private ArchilanMailer $mailer)
    {
    }

    public function __invoke(RegistrationConfirmationReminderMessage $message): void
    {
        $this->mailer->send(new RegistrationConfirmationReminderEmail(
            $message->userEmail,
            $message->userDisplayName,
            $message->eventTitle,
            $message->registrationClosesAt,
            $message->recapUrl,
        ));
    }
}
