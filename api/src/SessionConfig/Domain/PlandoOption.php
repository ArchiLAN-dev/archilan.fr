<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * Archipelago generator plando module (--plando). Values match Archipelago exactly.
 */
enum PlandoOption: string
{
    case Bosses = 'bosses';
    case Items = 'items';
    case Texts = 'texts';
    case Connections = 'connections';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new \DomainException('invalid_plando_option');
    }
}
