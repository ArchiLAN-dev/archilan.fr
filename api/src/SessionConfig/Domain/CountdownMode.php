<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * Archipelago !countdown policy. Values match ArchipelagoServer exactly.
 */
enum CountdownMode: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Auto = 'auto';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new \DomainException('invalid_countdown_mode');
    }
}
