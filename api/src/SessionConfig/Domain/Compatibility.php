<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * Archipelago server compatibility level (--compatibility).
 * 2 = casual/cooperative, 1 = friendly racing, 0 = tournament (exact version match).
 */
enum Compatibility: int
{
    case Tournament = 0;
    case Racing = 1;
    case Casual = 2;

    public static function fromInt(int $value): self
    {
        return self::tryFrom($value) ?? throw new \DomainException('invalid_compatibility');
    }
}
