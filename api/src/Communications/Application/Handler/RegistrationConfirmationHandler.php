<?php

declare(strict_types=1);

namespace App\Communications\Application\Handler;

use App\Communications\Application\Email\RegistrationConfirmationEmail;
use App\Communications\Application\Message\RegistrationConfirmationMessage;
use App\Communications\Application\Service\ArchilanMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RegistrationConfirmationHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private string $siteUrl = '',
    ) {
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
            '' !== $message->eventId && '' !== $message->registrationId
                ? sprintf('%s/evenements/%s/inscription/%s/recap', rtrim($this->siteUrl, '/'), $message->eventId, $message->registrationId)
                : '',
            $this->siteUrl,
            // Story 53.7: the registrant hub, where the member comes back before and during the event.
            '' !== $message->eventId
                ? sprintf('%s/evenements/%s/mon-event', rtrim($this->siteUrl, '/'), $message->eventId)
                : '',
        ));
    }
}
