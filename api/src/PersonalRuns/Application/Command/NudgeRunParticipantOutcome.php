<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

enum NudgeRunParticipantOutcome
{
    case Nudged;
    case NotFound;
    /** The caller is not in the run. */
    case Forbidden;
    /** The run is neither active nor idle. */
    case RunNotPlaying;
    /** Oneself, someone who is not a player, who played lately, whose slots are done, or a block either way. */
    case NotIdle;
    /** The player turned nudges off for this run. */
    case Muted;
    /** Nudged by someone less than a day ago; the result carries when. */
    case AlreadyNudged;
}
