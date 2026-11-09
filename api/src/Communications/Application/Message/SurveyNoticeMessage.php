<?php

declare(strict_types=1);

namespace App\Communications\Application\Message;

/** Story 51.3: a survey opened for the member, or a reminder that they have not answered it yet. */
final readonly class SurveyNoticeMessage
{
    public function __construct(
        public string $userEmail,
        public ?string $userDisplayName,
        public string $surveyTitle,
        public string $eventTitle,
        public string $closesAt,
        public bool $anonymous,
        public string $answerUrl,
        public bool $reminder,
    ) {
    }
}
