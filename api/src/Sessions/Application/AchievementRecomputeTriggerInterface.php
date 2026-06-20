<?php

declare(strict_types=1);

namespace App\Sessions\Application;

/**
 * Lets the session-archival flow ask the Community context to (re)evaluate a set of players' achievements
 * after their run is finalized (story 30.26). Defined here in the consumer context; the Community
 * Infrastructure adapter implements it (and dispatches asynchronously) so Sessions stays decoupled from
 * the achievement engine.
 */
interface AchievementRecomputeTriggerInterface
{
    /**
     * @param list<string> $userIds players whose achievements should be recomputed (with notification)
     */
    public function recomputeForUsers(array $userIds): void;
}
