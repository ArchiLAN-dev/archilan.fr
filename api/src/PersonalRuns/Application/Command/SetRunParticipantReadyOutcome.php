<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

enum SetRunParticipantReadyOutcome
{
    /** The status is what the player asked for, now or already. */
    case Updated;

    /** No such run, or the caller does not play it. */
    case NotFound;

    /** The run is launched: being ready no longer means anything. */
    case Launched;

    /** Neither a game of their own nor a slot they play as a co-player. */
    case NothingToPlay;
}
