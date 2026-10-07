<?php

declare(strict_types=1);

namespace App\Community\Domain\Enum;

/**
 * How a profile title shines (story 41.27): the admin picks it per title. Common is plain, rare a frank colour,
 * epic a gradient with a glow, legendary gold with a passing sheen.
 */
enum TitleRarity: string
{
    case Common = 'common';
    case Rare = 'rare';
    case Epic = 'epic';
    case Legendary = 'legendary';

    public function label(): string
    {
        return match ($this) {
            self::Common => 'Commun',
            self::Rare => 'Rare',
            self::Epic => 'Épique',
            self::Legendary => 'Légendaire',
        };
    }
}
