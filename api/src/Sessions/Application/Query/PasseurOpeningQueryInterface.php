<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/** Story 52.5: the calls to the Passeur, newest first. */
interface PasseurOpeningQueryInterface
{
    /**
     * @return list<PasseurOpeningRow>
     */
    public function forSession(string $sessionId): array;

    /**
     * @return list<PasseurOpeningRow>
     */
    public function forEvent(string $eventId): array;

    /**
     * The chests opened in a player's world that their notice still lists.
     *
     * @return list<PasseurOpeningRow>
     */
    public function unseenBy(string $sessionId, string $absentUserId): array;
}
