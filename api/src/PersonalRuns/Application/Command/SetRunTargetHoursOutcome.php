<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

/** Story 56.5. */
enum SetRunTargetHoursOutcome
{
    case Done;
    case NotFound;
    case Forbidden;
    case Invalid;
}
