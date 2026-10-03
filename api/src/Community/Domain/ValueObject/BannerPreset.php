<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * Curated banner presets (no image upload this epic). The frontend maps each key to a gradient/treatment.
 */
final readonly class BannerPreset
{
    public const string DEFAULT = 'default';

    public const array ALL = [
        self::DEFAULT,
        'sunset',
        'forest',
        'arcade',
        'midnight',
        'aurora',
        'ocean',
        'neon',
        'retrowave',
        'pastel',
    ];

    /**
     * Story 41.7: the presets sold in the shop, usable only by who bought them. Empty until members draw some.
     *
     * @var list<string>
     */
    public const array SHOP = [];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    /**
     * @param list<string> $owned the shop presets the account bought
     * @param list<string> $shop  the shop presets (the catalog's, by default)
     */
    public static function allowedFor(string $value, array $owned = [], array $shop = self::SHOP): bool
    {
        return !in_array($value, $shop, true) || in_array($value, $owned, true);
    }
}
