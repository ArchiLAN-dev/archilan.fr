<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

enum JoinOpenRunOutcome
{
    /** In the run, now or already. */
    case Joined;

    /** No such run, not open to friends, or the caller is not a friend of its owner: the run stays hidden. */
    case NotFound;

    /** Every seat offered is taken. */
    case Full;
}
