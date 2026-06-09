<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * Archipelago !remaining policy. Values match ArchipelagoServer exactly.
 */
enum RemainingMode: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Goal = 'goal';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new \DomainException('invalid_remaining_mode');
    }
}
