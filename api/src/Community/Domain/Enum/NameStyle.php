<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/**
 * The holographic look of a member's name (story 30.44), like the title of a trading card: gold for an admin,
 * silver for a member. Decided at read time from the current status, never stored, so it goes with the status
 * and comes back with it; the owner may turn it off.
 */
enum NameStyle: string
{
    case Gold = 'gold';
    case Silver = 'silver';

    public static function for(bool $isAdmin, bool $isMember, bool $enabled): ?self
    {
        if (!$enabled) {
            return null;
        }
        if ($isAdmin) {
            return self::Gold;
        }

        return $isMember ? self::Silver : null;
    }
}
