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
    /** Fixed after the 1.1.0 review: a finished or cancelled run keeps the target it was played with. */
    case RunTerminal;
}
