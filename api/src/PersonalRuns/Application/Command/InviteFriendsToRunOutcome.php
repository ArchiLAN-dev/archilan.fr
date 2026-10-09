<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

enum InviteFriendsToRunOutcome
{
    case Invited;

    case NotFound;

    /** Only the owner invites by name. */
    case Forbidden;

    /** A finished or cancelled run takes no one in. */
    case RunEnded;

    /** More than InviteFriendsToRun::MAX_PER_DAY invitations over 24 hours. */
    case DailyLimit;
}
