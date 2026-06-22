<?php

declare(strict_types=1);

namespace App\Community\Application;

interface EventParticipationQueryInterface
{
    /**
     * Distinct event ids where the user reached a goal: a finished event session has a slot tied to the
     * user's confirmed registration with a goal reached. Feeds the event-participation achievement facts
     * (story 30.32).
     *
     * @return list<string>
     */
    public function eventIdsWithGoal(string $userId): array;
}
