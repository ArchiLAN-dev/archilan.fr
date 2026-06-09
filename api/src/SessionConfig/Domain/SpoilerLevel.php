<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * Archipelago generator spoiler level (--spoiler).
 * 0 = none, 1 = no playthrough, 2 = with playthrough, 3 = with playthrough + paths.
 */
enum SpoilerLevel: int
{
    case None = 0;
    case NoPlaythrough = 1;
    case WithPlaythrough = 2;
    case WithPaths = 3;

    public static function fromInt(int $value): self
    {
        return self::tryFrom($value) ?? throw new \DomainException('invalid_spoiler');
    }
}
