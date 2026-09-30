<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/**
 * A member's titled name (story 30.44): a title above the name and the colours of loot rarity - legendary for an
 * admin, epic for a member. Decided at read time from the current status, never stored, so it goes with the
 * status and comes back with it; the owner may turn it off.
 */
enum NameStyle: string
{
    case Legendary = 'legendary';
    case Epic = 'epic';

    public static function for(bool $isAdmin, bool $isMember, bool $enabled): ?self
    {
        if (!$enabled) {
            return null;
        }
        if ($isAdmin) {
            return self::Legendary;
        }

        return $isMember ? self::Epic : null;
    }
}
