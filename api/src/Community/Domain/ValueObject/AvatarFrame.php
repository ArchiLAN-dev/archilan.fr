<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * Curated decorative avatar frames. Null means "no frame". The frontend maps each key to a ring treatment
 * (flat colour, neon glow, an animated effect, or a looping video overlay); the backend only validates the key.
 */
final readonly class AvatarFrame
{
    public const array ALL = [
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
        'fire',
        'electric',
        'spectral_fire',
        'lava',
        'runes',
        'cosmic',
        'glitch',
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
