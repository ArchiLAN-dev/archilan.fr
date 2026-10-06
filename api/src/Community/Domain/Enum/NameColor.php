<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/**
 * A colour a member buys for their name (story 41.23), from a palette readable on the site's dark background. Shown
 * where the name has no rarity colour (30.44): the rarity of an admin or a member comes first, unless they turned
 * their titled name off.
 */
enum NameColor: string
{
    case Emerald = 'emerald';
    case Azure = 'azure';
    case Ruby = 'ruby';
    case Amber = 'amber';
    case Amethyst = 'amethyst';
    case Turquoise = 'turquoise';
    case Lime = 'lime';
    case Pink = 'pink';

    /** The prefix the name style carries a colour with (`color-emerald`). */
    public const string STYLE_PREFIX = 'color-';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $color): string => $color->value, self::cases());
    }

    /**
     * The style a name shows: its rarity (30.44) when the status gives one and the owner keeps it on, otherwise the
     * colour they bought (41.23), otherwise none.
     */
    public static function nameStyle(bool $isAdmin, bool $isMember, bool $titledName, ?string $color): ?string
    {
        $rarity = NameStyle::for($isAdmin, $isMember, $titledName);
        if (null !== $rarity) {
            return $rarity->value;
        }
        $bought = null === $color ? null : self::tryFrom($color);

        return null === $bought ? null : self::STYLE_PREFIX.$bought->value;
    }
}
