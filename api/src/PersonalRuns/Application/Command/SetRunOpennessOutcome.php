<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

enum SetRunOpennessOutcome
{
    case Updated;

    case NotFound;

    /** Only the owner opens their run. */
    case Forbidden;

    /** The run left draft: its players are set. */
    case Locked;

    /** Unknown openness, a number of seats out of range, or a listing without a fitting message. */
    case Invalid;

    /** Story 43.17: a suspended member cannot list a run. */
    case Sanctioned;
}
