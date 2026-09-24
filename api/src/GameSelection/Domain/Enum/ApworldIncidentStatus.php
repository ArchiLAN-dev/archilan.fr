<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * Lifecycle of an apworld incident (story 38.1). `Open` and `Acknowledged` are active: they block a
 * second incident for the same key and are still resolved automatically when the verdict turns
 * green. `Resolved` and `Ignored` are terminal.
 */
enum ApworldIncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    public function isActive(): bool
    {
        return self::Open === $this || self::Acknowledged === $this;
    }
}
