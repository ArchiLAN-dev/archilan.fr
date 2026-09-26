<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Enum;

/**
 * What happens to a slot's YAML when its game switches apworld (story 38.7).
 */
enum SlotYamlCase: string
{
    /** Never touched by the player: it becomes the new default YAML. */
    case ReplaceWithDefault = 'replace_with_default';
    /** Customised, and every setting still holds: kept exactly as it is. */
    case Keep = 'keep';
    /** Customised, and something no longer holds: kept as it is, the player reviews it. */
    case NeedsReview = 'needs_review';
}
