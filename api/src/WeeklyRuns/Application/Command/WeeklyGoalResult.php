<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Command;

/**
 * Result of a goal-reached callback for a weekly entry ({@see RecordWeeklyGoal::execute}, and passed through
 * by {@see \App\Sessions\Application\Command\RecordSlotGoal::execute} when the session is a weekly entry).
 */
final readonly class WeeklyGoalResult
{
    public function __construct(
        public string $entryId,
    ) {
    }
}
