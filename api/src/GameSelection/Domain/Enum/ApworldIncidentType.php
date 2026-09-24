<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * What an apworld incident accuses (story 38.1). The type is part of the deduplication key: one
 * active incident per (game, apworld hash, type), so two different problems on the same apworld
 * are tracked separately while the same problem seen every night stays one incident.
 */
enum ApworldIncidentType: string
{
    /** The solo test generation with the default template failed (story 9.38 verdict). */
    case PreflightFailed = 'preflight_failed';

    /**
     * Whether the incident is about the apworld the game serves, and so closes by itself once the
     * game serves another hash. False for a type keyed on an apworld the game never served, such as
     * a rejected update candidate (story 38.6).
     */
    public function followsServedApworld(): bool
    {
        return match ($this) {
            self::PreflightFailed => true,
        };
    }
}
