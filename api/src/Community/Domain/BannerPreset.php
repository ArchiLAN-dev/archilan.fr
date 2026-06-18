<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Curated banner presets (no image upload this epic). The frontend maps each key to a gradient/treatment.
 */
final class BannerPreset
{
    public const DEFAULT = 'default';

    public const ALL = [
        self::DEFAULT,
        'sunset',
        'forest',
        'arcade',
        'midnight',
        'aurora',
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
