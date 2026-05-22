<?php

declare(strict_types=1);

namespace App\Sessions\Application;

interface CommunityStatsQueryInterface
{
    /**
     * @return array{totalFinishedSessions: int, totalChecksDone: int, totalGoalsReached: int}
     */
    public function execute(): array;
}
