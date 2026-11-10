<?php

declare(strict_types=1);

namespace App\Communications\Application\Handler;

use App\Communications\Application\Email\SurveyOpenedEmail;
use App\Communications\Application\Email\SurveyReminderEmail;
use App\Communications\Application\Message\SurveyNoticeMessage;
use App\Communications\Application\Service\ArchilanMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SurveyNoticeHandler
{
    public function __construct(
        private ArchilanMailer $mailer,
        private string $siteUrl = '',
    ) {
    }

    public function __invoke(SurveyNoticeMessage $message): void
    {
        $email = $message->reminder ? SurveyReminderEmail::class : SurveyOpenedEmail::class;

        $this->mailer->send(new $email(
            $message->userEmail,
            $message->userDisplayName,
            $message->surveyTitle,
            $message->eventTitle,
            $message->closesAt,
            $message->anonymous,
            $message->answerUrl,
            $this->siteUrl,
        ));
    }
}
