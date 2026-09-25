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
     * A new apworld version failed its test, so the game kept its current one (story 38.6). Keyed on
     * the candidate hash: a later version opens a new incident.
     */
    case UpdateRejected = 'update_rejected';

    /**
     * The nightly update found several apworld files in a release and could not tell which one is the
     * game's (story 38.6). An admin has to pick it by hand.
     */
    case UpdateAmbiguous = 'update_ambiguous';

    /**
     * A real generation failed with the game's default YAML on the apworld the game serves (story
     * 38.4): a player's solo config test or a run generation. The import test can pass on a lucky seed.
     */
    case DefaultYamlFailure = 'default_yaml_failure';

    /**
     * Whether the incident is about the apworld the game serves, and so closes by itself once the
     * game serves another hash. False for a type keyed on an apworld the game never served, such as
     * a rejected update candidate (story 38.6).
     */
    public function followsServedApworld(): bool
    {
        return match ($this) {
            self::PreflightFailed, self::DefaultYamlFailure => true,
            self::UpdateRejected, self::UpdateAmbiguous => false,
        };
    }

    /**
     * Whether a later promotion of any apworld for the game settles the incident: the game got its
     * update after all (story 38.6).
     */
    public function isSettledByAPromotion(): bool
    {
        return match ($this) {
            self::PreflightFailed, self::DefaultYamlFailure => false,
            self::UpdateRejected, self::UpdateAmbiguous => true,
        };
    }
}
