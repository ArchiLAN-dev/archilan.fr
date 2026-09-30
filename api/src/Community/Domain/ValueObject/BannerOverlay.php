<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * How strongly the banner preset is laid over a banner image (story 30.41), in percent: 0 shows the image alone,
 * 100 covers it with the preset.
 */
final readonly class BannerOverlay
{
    public const int DEFAULT = 50;

    public static function isValid(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 100;
    }
}
