<?php

declare(strict_types=1);

namespace App\Sessions\Application;

final readonly class CommunityStatsQuery
{
    public function __construct(private CommunityStatsQueryInterface $query)
    {
    }

    /**
     * @return array{totalFinishedSessions: int, totalChecksDone: int, totalGoalsReached: int}
     */
    public function execute(): array
    {
        return $this->query->execute();
    }
}
