<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * Archipelago !release / !collect policy. Values match ArchipelagoServer exactly.
 */
enum ReleaseCollectMode: string
{
    case Disabled = 'disabled';
    case Enabled = 'enabled';
    case Goal = 'goal';
    case Auto = 'auto';
    case AutoEnabled = 'auto-enabled';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new \DomainException('invalid_release_collect_mode');
    }
}
