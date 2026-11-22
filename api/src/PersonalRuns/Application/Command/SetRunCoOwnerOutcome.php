<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

/** Story 16.27: what came of naming or removing a co-organiser. */
enum SetRunCoOwnerOutcome
{
    case Done;
    case RunNotFound;
    case Forbidden;
    case ParticipantNotFound;
    /** The owner is not a co-organiser of their own run. */
    case IsOwner;
    case RunTerminal;
}
