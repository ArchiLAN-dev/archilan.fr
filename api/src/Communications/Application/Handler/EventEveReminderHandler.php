<?php

declare(strict_types=1);

namespace App\Communications\Application\Handler;

use App\Communications\Application\Email\EventEveReminderEmail;
use App\Communications\Application\Message\EventEveReminderMessage;
use App\Communications\Application\Service\ArchilanMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EventEveReminderHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private string $siteUrl = '',
    ) {
    }

    public function __invoke(EventEveReminderMessage $message): void
    {
        $this->mailer->send(new EventEveReminderEmail(
            $message->userEmail,
            $message->userDisplayName,
            $message->eventTitle,
            $message->startsAt,
            $message->venue,
            $message->stepsLeft,
            $message->hubUrl,
            $this->siteUrl,
            $message->localBroadcast,
        ));
    }
}
