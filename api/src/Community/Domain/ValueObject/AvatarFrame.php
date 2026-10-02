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

    /** Story 30.46: the video frames, reserved to admins for a start. */
    public const array LEGENDARY = ['fire', 'electric', 'spectral_fire', 'lava', 'runes', 'cosmic', 'glitch'];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    public static function isLegendary(string $value): bool
    {
        return in_array($value, self::LEGENDARY, true);
    }

    /** Whether this account may pick the frame: a legendary one is for admins only. */
    public static function allowedFor(string $value, bool $isAdmin): bool
    {
        return $isAdmin || !self::isLegendary($value);
    }

    /**
     * The frame a profile shows: a legendary one only while its owner is still admin, like an admin's animated
     * avatar (story 30.40). The stored key is kept, so a re-promoted admin gets it back.
     */
    public static function displayed(?string $value, bool $isAdmin): ?string
    {
        return null !== $value && self::allowedFor($value, $isAdmin) ? $value : null;
    }
}
