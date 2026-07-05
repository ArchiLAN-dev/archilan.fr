<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

use App\Events\Domain\Event;

/**
 * The three session types that carry a config profile.
 */
enum SessionType: string
{
    case Private = 'private';
    case Event = 'event';
    case Weekly = 'weekly';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new \DomainException('unknown_session_type');
    }
}
