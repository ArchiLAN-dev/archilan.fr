<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Enum;

/** Who a survey speaks to (story 51.1). Fixed once the survey is published. */
enum SurveyAudience: string
{
    /** Whoever registers, during the registration (story 51.5), and those registered before the survey existed. */
    case Registration = 'registration';
    /** The active registrations of the event. */
    case Registrants = 'registrants';
    /** The registrations that played (story 51.2 defines it). */
    case Participants = 'participants';
}
