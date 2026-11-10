<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/**
 * What an achievement counter counts in the session feed (story 30.57): a location checked by a slot the member
 * plays (the world of the check is the sender's), or an item received by a slot the member plays (the world of
 * the item is the receiver's).
 */
enum AchievementCounterKind: string
{
    case Location = 'location';
    case Item = 'item';

    public function label(): string
    {
        return match ($this) {
            self::Location => 'Check fait',
            self::Item => 'Item reçu',
        };
    }
}
