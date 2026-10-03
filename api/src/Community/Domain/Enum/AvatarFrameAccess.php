<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/** Who may wear a video frame of the catalog (story 41.10). */
enum AvatarFrameAccess: string
{
    case Free = 'free';
    case Members = 'members';
    case Admins = 'admins';
    case Shop = 'shop';

    /**
     * @param list<string> $owned the shop frames the account bought
     */
    public function allows(string $key, bool $isAdmin, bool $isMember, array $owned): bool
    {
        return match ($this) {
            self::Free => true,
            self::Members => $isAdmin || $isMember,
            self::Admins => $isAdmin,
            self::Shop => in_array($key, $owned, true),
        };
    }
}
