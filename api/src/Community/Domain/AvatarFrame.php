<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Curated decorative avatar frames. Null means "no frame". The frontend maps each key to a ring treatment
 * (flat colour, neon glow, or an animated effect); the backend only validates the key.
 */
final class AvatarFrame
{
    public const ALL = [
        'gold',
        'silver',
        'bronze',
        'crimson',
        'emerald',
        'sapphire',
        'violet',
        'neon_pink',
        'neon_cyan',
        'neon_green',
        'toxic',
        'holographic',
        'gold_shimmer',
        'spectral',
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
