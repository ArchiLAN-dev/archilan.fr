<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Service;

/** Result of putting a game on one of a player's lists, or taking it off (story 28.13). */
enum GameListOutcome
{
    case Added;
    case Removed;
    case GameNotFound;
}
