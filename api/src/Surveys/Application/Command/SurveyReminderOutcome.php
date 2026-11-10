<?php

declare(strict_types=1);

namespace App\Surveys\Application\Command;

enum SurveyReminderOutcome
{
    case Sent;
    case TooSoon;
}
