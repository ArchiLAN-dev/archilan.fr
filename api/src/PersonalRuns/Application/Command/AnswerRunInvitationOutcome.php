<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

enum AnswerRunInvitationOutcome
{
    case Joined;

    case Declined;

    /** No such invitation for this member. */
    case NotFound;

    /** Already declined or closed. */
    case NoLongerOpen;

    /** The run is finished, cancelled or gone: the invitation is closed. */
    case RunEnded;

    /** The friendship is gone, or a block stands: the invitation is closed. */
    case NoLongerFriends;
}
