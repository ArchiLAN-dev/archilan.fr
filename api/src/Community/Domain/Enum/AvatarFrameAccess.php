<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/** Who may wear a video frame of the catalog (story 41.10). Story 41.28: or won, through an achievement or a quest. */
enum AvatarFrameAccess: string
{
    case Free = 'free';
    case Members = 'members';
    case Admins = 'admins';
    case Shop = 'shop';
    case Reward = 'reward';

    /**
     * @param list<string> $owned the cosmetics of that kind the account owns (bought, or won)
     */
    public function allows(string $key, bool $isAdmin, bool $isMember, array $owned): bool
    {
        return match ($this) {
            self::Free => true,
            self::Members => $isAdmin || $isMember,
            self::Admins => $isAdmin,
            self::Shop, self::Reward => in_array($key, $owned, true),
        };
    }
}
