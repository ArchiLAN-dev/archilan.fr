<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/** How a quest got into a week (story 41.15): pinned by an admin, or drawn at random. */
enum QuestWeekOrigin: string
{
    case Pinned = 'pinned';
    case Drawn = 'drawn';
}
