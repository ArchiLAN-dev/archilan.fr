<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Recompute one player's achievements with notification (story 30.26). Dispatched after a run is archived
 * so a freshly-unlocked achievement is granted and the player is told. Idempotent: grants are monotonic.
 */
final readonly class RecomputeAchievementsForUserMessage
{
    public function __construct(public string $userId)
    {
    }
}
