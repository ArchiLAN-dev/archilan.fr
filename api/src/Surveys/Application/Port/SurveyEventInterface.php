<?php

declare(strict_types=1);

namespace App\Surveys\Application\Port;

use App\Surveys\Application\Support\SurveyEventPeriod;

/** The event a survey belongs to, read in the context that owns it (story 51.1). Surveys knows no event entity. */
interface SurveyEventInterface
{
    /** Null when the event does not exist. */
    public function period(string $eventId): ?SurveyEventPeriod;
}
