<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Daily backstop (story 30.26): recompute every player's achievements silently, so any unlock missed by
 * the real-time post-archive path (e.g. a lost archive callback) is still eventually granted - without
 * notification spam. Monotonic + idempotent.
 */
final readonly class RecomputeAllAchievementsMessage
{
}
