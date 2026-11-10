<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

interface PlayerConnectionQueryInterface
{
    /**
     * The latest session of the event in which the member plays: a slot of their registration, or a slot they were
     * added to as a co-player (story 11.10), even when none of their own slots went into that session.
     */
    public function findLatestSessionIdForPlayer(string $eventId, string $registrationId, string $userId): ?string;
}
