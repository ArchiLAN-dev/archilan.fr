<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/** Where a member's follow of a game stands after {@see FollowGame} (story 38.22). */
enum FollowGameOutcome
{
    case Following;
    case NotFollowing;
    case GameNotFound;
}
